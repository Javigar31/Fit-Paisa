<?php
/**
 * FitPaisa — Endpoint del Panel de Entrenador/a (Coach)
 *
 * Accesible SOLO para usuarios con rol COACH o ADMIN (verificado via JWT).
 *
 * Rutas:
 *   GET  /api/coach.php?action=dashboard        → KPIs del coach
 *   GET  /api/coach.php?action=clients          → Lista de clientes del coach
 *   GET  /api/coach.php?action=plans            → Planes creados por el coach
 *   POST /api/coach.php?action=update_settings  → Actualizar perfil del coach
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 * @version  1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_jwt.php';

fp_cors();

/* ── Auth: solo COACH o ADMIN ──────────────────────────────────────── */
$payload = jwt_require();
$role    = $payload['role'] ?? '';

if (!in_array($role, ['COACH', 'ADMIN'], true)) {
    fp_error(403, 'Acceso restringido a entrenadores.');
}

$coachId = (int) $payload['user_id'];
$action  = fp_sanitize($_GET['action'] ?? 'dashboard', 32, 'slug');

match ($action) {
    'dashboard'       => handle_dashboard($coachId),
    'clients'         => handle_clients($coachId),
    'plans'           => handle_plans($coachId),
    'update_settings' => handle_update_settings($coachId, $payload),
    default           => fp_error(400, "Acción '{$action}' no reconocida."),
};

/* ══════════════════════════════════════════════════════════════════════
   DASHBOARD — KPIs principales del coach
   ══════════════════════════════════════════════════════════════════════ */
function handle_dashboard(int $coachId): never
{
    /* Clientes activos (usuarios con al menos un plan ACTIVE del coach) */
    $activeClients = (int) fp_query(
        "SELECT COUNT(DISTINCT wp.user_id)
         FROM workout_plans wp
         WHERE wp.coach_id = :cid AND wp.status = 'ACTIVE'",
        [':cid' => $coachId]
    )->fetchColumn();

    /* Total de planes del coach por estado */
    $plansStats = fp_query(
        "SELECT
             COUNT(*) AS total_plans,
             COUNT(*) FILTER (WHERE status = 'ACTIVE')  AS active_plans,
             COUNT(*) FILTER (WHERE status = 'DRAFT')   AS draft_plans,
             COUNT(*) FILTER (WHERE status = 'PENDING') AS pending_plans
         FROM workout_plans
         WHERE coach_id = :cid",
        [':cid' => $coachId]
    )->fetch();

    /* Mensajes no leídos recibidos por el coach */
    $unreadMessages = (int) fp_query(
        "SELECT COUNT(*)
         FROM messages
         WHERE receiver_id = :cid AND read_at IS NULL AND is_deleted = FALSE",
        [':cid' => $coachId]
    )->fetchColumn();

    /* Clientes recientes con plan activo */
    $recentClients = fp_query(
        "SELECT DISTINCT ON (u.user_id)
                u.user_id, u.full_name, u.email,
                wp.name AS plan_name, wp.status AS plan_status,
                wp.created_at AS plan_created
         FROM workout_plans wp
         JOIN users u ON u.user_id = wp.user_id
         WHERE wp.coach_id = :cid
         ORDER BY u.user_id, wp.created_at DESC
         LIMIT 5",
        [':cid' => $coachId]
    )->fetchAll();

    /* Planes recientes */
    $recentPlans = fp_query(
        "SELECT wp.plan_id, wp.name, wp.status, wp.created_at,
                u.full_name AS client_name
         FROM workout_plans wp
         JOIN users u ON u.user_id = wp.user_id
         WHERE wp.coach_id = :cid
         ORDER BY wp.created_at DESC
         LIMIT 5",
        [':cid' => $coachId]
    )->fetchAll();

    fp_success([
        'kpis' => [
            'active_clients'  => $activeClients,
            'active_plans'    => (int) ($plansStats['active_plans'] ?? 0),
            'total_plans'     => (int) ($plansStats['total_plans']  ?? 0),
            'draft_plans'     => (int) ($plansStats['draft_plans']  ?? 0),
            'pending_plans'   => (int) ($plansStats['pending_plans'] ?? 0),
            'unread_messages' => $unreadMessages,
        ],
        'recent_clients' => $recentClients,
        'recent_plans'   => $recentPlans,
    ]);
}

/* ══════════════════════════════════════════════════════════════════════
   CLIENTS — Lista completa de clientes del coach
   ══════════════════════════════════════════════════════════════════════ */
function handle_clients(int $coachId): never
{
    /* Un "cliente" es cualquier usuario con al menos un plan de este coach */
    $clients = fp_query(
        "SELECT DISTINCT ON (user_id) *
         FROM (
             SELECT u.user_id, u.full_name, u.email, u.is_active, p.weight, p.height, p.objective,
                    wp.name AS current_plan_name, wp.status AS current_plan_status, wp.plan_id AS current_plan_id,
                    wp.created_at AS plan_created_at
             FROM users u
             JOIN profiles p ON p.user_id = u.user_id
             LEFT JOIN workout_plans wp ON wp.user_id = u.user_id AND wp.coach_id = :cid
             WHERE p.preferred_coach_id = :cid OR wp.coach_id = :cid
         ) sub
         ORDER BY user_id, plan_created_at DESC",
        [':cid' => $coachId]
    )->fetchAll();

    fp_success(['clients' => $clients]);
}

/* ══════════════════════════════════════════════════════════════════════
   PLANS — Todos los planes creados por el coach
   ══════════════════════════════════════════════════════════════════════ */
function handle_plans(int $coachId): never
{
    $plans = fp_query(
        "SELECT wp.plan_id, wp.name, wp.status, wp.start_date, wp.end_date, wp.created_at,
                u.full_name AS client_name, u.user_id AS client_id
         FROM workout_plans wp
         JOIN users u ON u.user_id = wp.user_id
         WHERE wp.coach_id = :cid
         ORDER BY wp.created_at DESC",
        [':cid' => $coachId]
    )->fetchAll();

    fp_success(['plans' => $plans]);
}

/* ══════════════════════════════════════════════════════════════════════
   UPDATE SETTINGS — Actualizar nombre/email del coach
   ══════════════════════════════════════════════════════════════════════ */
function handle_update_settings(int $coachId, array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }

    $body    = fp_json_body();
    $name    = fp_sanitize($body['full_name'] ?? '', 120);
    $email   = filter_var($body['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $updates = [];
    $params  = [':uid' => $coachId];

    if (!empty($name)) {
        $updates[]      = 'full_name = :name';
        $params[':name'] = $name;
    }

    if ($email) {
        /* Verificar que el email no esté en uso por otro usuario */
        $exists = fp_query(
            'SELECT 1 FROM users WHERE email = :email AND user_id != :uid',
            [':email' => $email, ':uid' => $coachId]
        )->fetchColumn();

        if ($exists) {
            fp_error(409, 'El correo ya está en uso.');
        }

        $updates[]       = 'email = :email';
        $params[':email'] = $email;
    }

    if (empty($updates)) {
        fp_error(400, 'Sin campos a actualizar.');
    }

    fp_query(
        'UPDATE users SET ' . implode(', ', $updates) . ' WHERE user_id = :uid',
        $params
    );

    fp_success(['message' => 'Perfil actualizado correctamente.']);
}
