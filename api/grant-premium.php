<?php
/**
 * FitPaisa — Grant Premium Script
 *
 * Activa una suscripción PREMIUM_MONTHLY ACTIVE para un usuario por email.
 * No requiere pasarela de pago — útil para usuarios de prueba o cortesía.
 *
 * Uso: GET /api/grant-premium.php?token=SETUP_TOKEN&email=user@host.com&months=12
 *
 * @package  FitPaisa\Api
 * @version  1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

header('Content-Type: application/json; charset=utf-8');

/* ── Protección por token ─────────────────────────────────────────────── */
$setupToken = getenv('SETUP_TOKEN');
if (!$setupToken || strlen($setupToken) < 16) {
    fp_error(500, 'SETUP_TOKEN no configurado.');
}
if (!hash_equals($setupToken, $_GET['token'] ?? '')) {
    fp_error(403, 'Token inválido. Acceso denegado.');
}

/* ── Parámetros ───────────────────────────────────────────────────────── */
$email  = strtolower(trim($_GET['email'] ?? ''));
$months = max(1, min(24, (int) ($_GET['months'] ?? 12)));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fp_error(400, 'Email inválido.');
}

$db = fp_db();

/* ── Buscar usuario ───────────────────────────────────────────────────── */
$user = $db->prepare("SELECT user_id, full_name, email, role FROM users WHERE email = :email");
$user->execute([':email' => $email]);
$user = $user->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    fp_error(404, "Usuario '{$email}' no encontrado. Créalo primero con seed-admin.php.");
}

$userId = (int) $user['user_id'];

/* ── Cancelar suscripciones previas activas ───────────────────────────── */
$db->prepare(
    "UPDATE subscriptions SET status = 'CANCELLED', updated_at = NOW()
     WHERE user_id = :uid AND status = 'ACTIVE'"
)->execute([':uid' => $userId]);

/* ── Insertar nueva suscripción PREMIUM ───────────────────────────────── */
$startDate = date('Y-m-d');
$endDate   = date('Y-m-d', strtotime("+{$months} months"));

$stmt = $db->prepare(
    "INSERT INTO subscriptions
        (user_id, plan_type, status, start_date, end_date, amount, provider, starts_at, ends_at, created_at, updated_at)
     VALUES
        (:uid, 'PREMIUM_MONTHLY', 'ACTIVE', :start, :end, 0.00, 'manual', :start_ts, :end_ts, NOW(), NOW())
     RETURNING subscription_id"
);
$stmt->execute([
    ':uid'      => $userId,
    ':start'    => $startDate,
    ':end'      => $endDate,
    ':start_ts' => $startDate . 'T00:00:00Z',
    ':end_ts'   => $endDate   . 'T23:59:59Z',
]);
$subId = $stmt->fetchColumn();

/* ── Respuesta ────────────────────────────────────────────────────────── */
http_response_code(200);
echo json_encode([
    'success'         => true,
    'message'         => "✅ Premium activado para {$user['full_name']}",
    'user_id'         => $userId,
    'email'           => $user['email'],
    'subscription_id' => $subId,
    'plan'            => 'PREMIUM_MONTHLY',
    'start_date'      => $startDate,
    'end_date'        => $endDate,
    'months'          => $months,
    'amount'          => '0.00 (manual/cortesía)',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
