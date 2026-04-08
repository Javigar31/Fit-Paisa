<?php
/**
 * FitPaisa — Endpoint de Administración
 *
 * Solo accesible para usuarios con rol ADMIN (verificado via JWT).
 *
 * Rutas:
 *   GET  /api/admin.php?action=stats           → KPIs del dashboard
 *   GET  /api/admin.php?action=users           → Lista paginada (?page=1&search=)
 *   GET  /api/admin.php?action=coaches         → Lista de entrenadores con conteo de clientes
 *   GET  /api/admin.php?action=recent_activity → Últimos 10 registros
 *   POST /api/admin.php?action=update_user     → Cambiar rol o estado de un usuario
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_jwt.php';

fp_cors();

$payload = jwt_require('ADMIN'); /* 401/403 si no es ADMIN */
$action  = fp_sanitize($_GET['action'] ?? 'stats', 32);

match ($action) {
    'stats'           => handle_stats(),
    'users'           => handle_users(),
    'coaches'         => handle_coaches(),
    'recent_activity' => handle_recent_activity(),
    'subscriptions'   => handle_subscriptions(),
    'update_user'     => handle_update_user($payload),
    default           => fp_error(400, "Acción '{$action}' no reconocida."),
};

/* ══════════════════════════════════════════════════════════════════════
   STATS — KPIs del dashboard
   ══════════════════════════════════════════════════════════════════════ */
function handle_stats(): never
{
    $stats = fp_query("
        SELECT
            COUNT(*)                                                         AS total_users,
            COUNT(*) FILTER (WHERE role = 'COACH')                          AS total_coaches,
            COUNT(*) FILTER (WHERE role = 'USER')                           AS total_regular,
            COUNT(*) FILTER (WHERE role = 'ADMIN')                          AS total_admins,
            COUNT(*) FILTER (WHERE is_active = TRUE)                         AS active_users,
            COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '7 days') AS new_this_week
        FROM users
    ")->fetch();

    $activeSubs = (int) fp_query("
        SELECT COUNT(DISTINCT user_id) FROM subscriptions WHERE status = 'ACTIVE'
    ")->fetchColumn();

    $mrr = $activeSubs * 9.99;

    fp_success([
        'stats' => array_merge($stats, [
            'active_subscriptions' => $activeSubs,
            'mrr_estimate'         => number_format($mrr, 2, '.', '')
        ]),
    ]);
}

/* ══════════════════════════════════════════════════════════════════════
   USERS — Lista paginada con búsqueda
   ══════════════════════════════════════════════════════════════════════ */
function handle_users(): never
{
    $search = fp_sanitize($_GET['search'] ?? '', 150);
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $limit  = 15;
    $offset = ($page - 1) * $limit;

    $where  = '';
    $params = [];

    if (!empty($search)) {
        $where              = 'WHERE (u.full_name ILIKE :search OR u.email ILIKE :search)';
        $params[':search']  = '%' . $search . '%';
    }

    $total = (int) fp_query(
        "SELECT COUNT(*) FROM users u {$where}",
        $params
    )->fetchColumn();

    $params[':limit']  = $limit;
    $params[':offset'] = $offset;

    $users = fp_query("
        SELECT u.user_id, u.full_name, u.email, u.role, u.is_active,
               u.created_at, u.last_login,
               s.plan_type AS subscription_plan
        FROM users u
        LEFT JOIN subscriptions s
            ON s.user_id = u.user_id AND s.status = 'ACTIVE'
        {$where}
        ORDER BY u.created_at DESC
        LIMIT :limit OFFSET :offset
    ", $params)->fetchAll();

    fp_success([
        'users' => $users,
        'total' => $total,
        'page'  => $page,
        'pages' => (int) ceil($total / $limit),
    ]);
}

/* ══════════════════════════════════════════════════════════════════════
   COACHES — Lista con conteo de clientes activos
   ══════════════════════════════════════════════════════════════════════ */
function handle_coaches(): never
{
    $coaches = fp_query("
        SELECT u.user_id, u.full_name, u.email, u.is_active, u.created_at,
               COUNT(DISTINCT wp.user_id) AS client_count
        FROM users u
        LEFT JOIN workout_plans wp
            ON wp.coach_id = u.user_id AND wp.status = 'ACTIVE'
        WHERE u.role = 'COACH'
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ")->fetchAll();

    fp_success(['coaches' => $coaches]);
}

/* ══════════════════════════════════════════════════════════════════════
   RECENT ACTIVITY — Últimos 10 usuarios registrados
   ══════════════════════════════════════════════════════════════════════ */
function handle_recent_activity(): never
{
    $recent = fp_query("
        SELECT user_id, full_name, email, role, is_active,
               created_at, last_login
        FROM users
        ORDER BY created_at DESC
        LIMIT 10
    ")->fetchAll();

    fp_success(['recent' => $recent]);
}

/* ══════════════════════════════════════════════════════════════════
   SUBSCRIPTIONS — Estadísticas y lista de suscripciones
   ══════════════════════════════════════════════════════════════════ */
function handle_subscriptions(): never
{
    $stats = fp_query("
        SELECT
            COUNT(*) FILTER (WHERE status = 'ACTIVE')                                        AS active,
            COUNT(*) FILTER (WHERE status = 'PENDING')                                       AS pending,
            COUNT(*) FILTER (WHERE status = 'CANCELLED'
                AND updated_at >= date_trunc('month', NOW()))                                AS cancelled_month,
            COUNT(*) FILTER (WHERE plan_type = 'PREMIUM_MONTHLY' AND status = 'ACTIVE') * 9  AS mrr_monthly,
            COUNT(*) FILTER (WHERE plan_type = 'PREMIUM_ANNUAL'  AND status = 'ACTIVE') * 1  AS mrr_annual
        FROM subscriptions
    ")->fetch();

    $mrrEstimate = ($stats['mrr_monthly'] ?? 0) + ($stats['mrr_annual'] ?? 0);

    $subscriptions = fp_query("
        SELECT s.subscription_id, s.plan_type, s.status, s.provider,
               s.starts_at, s.ends_at,
               u.full_name, u.email
        FROM subscriptions s
        JOIN users u ON u.user_id = s.user_id
        ORDER BY s.starts_at DESC
        LIMIT 50
    ")->fetchAll();

    fp_success([
        'stats'         => [
            'active'          => (int) ($stats['active']          ?? 0),
            'pending'         => (int) ($stats['pending']         ?? 0),
            'cancelled_month' => (int) ($stats['cancelled_month'] ?? 0),
            'mrr_estimate'    => number_format($mrrEstimate, 2, '.', ''),
        ],
        'subscriptions' => $subscriptions,
    ]);
}

/* ══════════════════════════════════════════════════════════════════
   UPDATE USER — Cambiar rol o estado (ADMIN no puede auto-modificarse)
   ══════════════════════════════════════════════════════════════════ */
function handle_update_user(array $adminPayload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }

    $body     = fp_json_body();
    $userId   = (int) ($body['user_id'] ?? 0);
    $role     = fp_sanitize($body['role']      ?? '', 10);
    $isActive = $body['is_active'] ?? null;

    if ($userId <= 0) {
        fp_error(400, 'ID de usuario inválido.');
    }
    if ($userId === $adminPayload['user_id']) {
        fp_error(403, 'No puedes modificar tu propia cuenta desde el panel admin.');
    }

    $updates = [];
    $params  = [':id' => $userId];

    if (!empty($role)) {
        if (!in_array($role, ['USER', 'COACH', 'ADMIN'], true)) {
            fp_error(400, 'Rol inválido. Usa USER, COACH o ADMIN.');
        }
        $updates[]        = 'role = :role';
        $params[':role']  = $role;
    }

    if ($isActive !== null) {
        $updates[]          = 'is_active = :active';
        $params[':active']  = $isActive ? 'true' : 'false';

        /* Si se reactiva, resetear bloqueo */
        if ($isActive) {
            $updates[] = 'login_attempts = 0';
            $updates[] = 'locked_until = NULL';
        }
    }

    if (empty($updates)) {
        fp_error(400, 'No se proporcionó ningún campo a actualizar.');
    }

    fp_query(
        'UPDATE users SET ' . implode(', ', $updates) . ' WHERE user_id = :id',
        $params
    );

    fp_success(['message' => 'Usuario actualizado correctamente.']);
}
