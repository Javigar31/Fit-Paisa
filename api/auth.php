<?php
/**
 * FitPaisa — Endpoint de Autenticación (Consolidado)
 *
 * Gestión de registro e inicio de sesión.
 * Versión MONOLÍTICA para máxima estabilidad en Vercel.
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 * @version  2.0.0 (Monolithic)
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

/* ══════════════════════════════════════════════════════════════════════
   JWT LOGIC
   ══════════════════════════════════════════════════════════════════════ */

function jwt_create(array $payload): string
{
    $secret = getenv('JWT_SECRET');
    $now = time();
    $header = rtrim(strtr(base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT'])), '+/', '-_'), '=');
    $payload = array_merge($payload, ['iat' => $now, 'exp' => $now + 7200]);
    $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$body}", $secret, true)), '+/', '-_'), '=');
    return "{$header}.{$body}.{$signature}";
}

function jwt_require(): array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authHeader, 'Bearer ')) fp_error(401, 'No autenticado.');
    $token = substr($authHeader, 7);
    $parts = explode('.', $token);
    if (count($parts) !== 3) fp_error(401, 'Token inválido.');
    $secret = getenv('JWT_SECRET');
    [$h, $b, $s] = $parts;
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$h}.{$b}", $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $s)) fp_error(401, 'Firma inválida.');
    $payload = json_decode(base64_decode(strtr($b, '-_', '+/')), true);
    if (!isset($payload['exp']) || $payload['exp'] < time()) fp_error(401, 'Expirado.');
    return $payload;
}

/* ══════════════════════════════════════════════════════════════════════
   AUTH ACTIONS
   ══════════════════════════════════════════════════════════════════════ */

fp_cors();
$action = fp_sanitize($_GET['action'] ?? '', 32, 'slug');

match ($action) {
    'register'        => handle_register(),
    'login'           => handle_login(),
    'me'              => handle_me(),
    'forgot_password' => handle_forgot_password(),
    'reset_password'  => handle_reset_password(),
    default           => fp_error(400, "Acción desconocida."),
};

function handle_register(): never
{
    $body = fp_json_body();
    fp_rate_limit('auth_register', 5, 3600);
    $email = strtolower(fp_sanitize($body['email'] ?? '', 150, 'email'));
    if (empty($email) || empty($body['password'])) fp_error(400, 'Datos incompletos.');

    if (fp_query('SELECT 1 FROM users WHERE email = :e', [':e'=>$email])->fetchColumn()) fp_error(409, 'Email ya existe.');

    $db = fp_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO users (email, password_hash, full_name, phone, role, is_active, created_at) VALUES (:e, :h, :n, :p, 'USER', TRUE, NOW()) RETURNING user_id");
        $stmt->execute([':e'=>$email, ':h'=>password_hash($body['password'], PASSWORD_BCRYPT, ['cost'=>12]), ':n'=>fp_sanitize($body['name']??''), ':p'=>fp_sanitize($body['phone']??'')]);
        $uid = $stmt->fetchColumn();

        $db->prepare("INSERT INTO profiles (user_id, weight, height, age, gender, objective, activity_level, updated_at) VALUES (:uid, 0.01, 0.01, 25, 'OTHER', 'MAINTAIN', 'MODERATE', NOW())")->execute([':uid'=>$uid]);
        $db->prepare("INSERT INTO subscriptions (user_id, plan_type, status, start_date, end_date, amount) VALUES (:uid, 'FREE', 'ACTIVE', CURRENT_DATE, CURRENT_DATE + INTERVAL '1 month', 0)")->execute([':uid'=>$uid]);
        
        $db->commit();
        $token = jwt_create(['user_id'=>$uid, 'email'=>$email, 'role'=>'USER', 'name'=>$body['name']??'']);
        fp_success([
            'token' => $token,
            'user'  => [
                'user_id' => $uid,
                'email'   => $email,
                'name'    => $body['name'] ?? '',
                'role'    => 'USER',
                'plan'    => 'FREE' // New users start free in this logic
            ]
        ], 201);
    } catch (Exception $e) { $db->rollBack(); fp_error(500, 'Error registro: '.$e->getMessage()); }
}

function handle_login(): never
{
    $body = fp_json_body();
    $email = strtolower(fp_sanitize($body['email'] ?? '', 150, 'email'));
    fp_rate_limit('auth_login', 10, 60);
    
    $user = fp_query("SELECT u.*, s.plan_type FROM users u LEFT JOIN subscriptions s ON s.user_id = u.user_id AND s.status='ACTIVE' WHERE u.email = :e LIMIT 1", [':e'=>$email])->fetch();
    if (!$user || !password_verify($body['password']??'', $user['password_hash'])) fp_error(401, 'Credenciales inválidas.');
    if (!$user['is_active']) fp_error(403, 'Bloqueada.');

    $token = jwt_create(['user_id'=>$user['user_id'], 'email'=>$user['email'], 'role'=>$user['role'], 'name'=>$user['full_name']]);
    fp_success(['token'=>$token, 'user'=>['user_id'=>$user['user_id'], 'email'=>$user['email'], 'name'=>$user['full_name'], 'role'=>$user['role'], 'plan'=>$user['plan_type']??'FREE']]);
}

function handle_me(): never
{
    $p = jwt_require();
    $u = fp_query("SELECT u.*, p.profile_id, s.plan_type FROM users u LEFT JOIN profiles p ON p.user_id=u.user_id LEFT JOIN subscriptions s ON s.user_id=u.user_id AND s.status='ACTIVE' WHERE u.user_id=:uid", [':uid'=>$p['user_id']])->fetch();
    fp_success(['user'=>$u]);
}

/**
 * Fase 1: Solicitar recuperación de contraseña.
 * Genera un código de 6 dígitos válido por 15 minutos.
 */
function handle_forgot_password(): never
{
    $body = fp_json_body();
    $email = strtolower(fp_sanitize($body['email'] ?? '', 150, 'email'));
    
    // Rate limit: 3 intentos por hora para evitar spam
    fp_rate_limit('auth_forgot', 3, 3600);

    if (empty($email)) fp_error(400, 'Email requerido.');

    // Verificar si el usuario existe (Clean Code: no revelamos si no existe por seguridad)
    $userExists = fp_query("SELECT 1 FROM users WHERE email = :e", [':e' => $email])->fetchColumn();
    
    if (!$userExists) {
        // Simulamos éxito para no dar pistas a atacantes
        error_log("[FitPaisa][AUTH] Intento recuperación email inexistente: $email");
        fp_success(['message' => 'Si el email existe, recibirás un código.']);
    }

    // Generar código numérico de 6 dígitos
    $code = (string)random_int(100000, 999999);
    $expires = date('Y-m-d H:i:s', time() + (15 * 60)); // 15 minutos

    try {
        fp_query(
            "INSERT INTO password_resets (email, code, expires_at) 
             VALUES (:e, :c, :ex) 
             ON CONFLICT (email) DO UPDATE SET code = :c, expires_at = :ex, created_at = NOW()",
            [':e' => $email, ':c' => $code, ':ex' => $expires]
        );

        // TODO: Enviar email real. Por ahora devolvemos el código en la respuesta para desarrollo.
        fp_success([
            'message' => 'Código de recuperación generado.',
            'dev_code' => $code // ELIMINAR EN PRODUCCIÓN
        ]);
    } catch (Exception $e) {
        error_log("[FitPaisa][AUTH] Error forgot_password: " . $e->getMessage());
        fp_error(500, 'Error al procesar la solicitud.');
    }
}

/**
 * Fase 2: Validar código y cambiar contraseña.
 */
function handle_reset_password(): never
{
    $body = fp_json_body();
    $email = strtolower(fp_sanitize($body['email'] ?? '', 150, 'email'));
    $code  = fp_sanitize($body['code'] ?? '', 6, 'slug');
    $pass  = $body['password'] ?? '';

    // Rate limit estricto para intentos de código (fuerza bruta)
    fp_rate_limit('auth_reset_attempt', 5, 300);

    if (empty($email) || empty($code) || empty($pass)) fp_error(400, 'Datos incompletos.');

    // Validar token/código en BD
    $reset = fp_query(
        "SELECT * FROM password_resets WHERE email = :e AND code = :c AND expires_at > NOW()",
        [':e' => $email, ':c' => $code]
    )->fetch();

    if (!$reset) {
        fp_error(401, 'Código inválido o expirado.');
    }

    // Actualizar contraseña del usuario
    $db = fp_db();
    $db->beginTransaction();
    try {
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        fp_query("UPDATE users SET password_hash = :h WHERE email = :e", [':h' => $hash, ':e' => $email]);
        
        // Limpiar tokens usados
        fp_query("DELETE FROM password_resets WHERE email = :e", [':e' => $email]);

        $db->commit();
        fp_success(['message' => 'Contraseña actualizada con éxito.']);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("[FitPaisa][AUTH] Error reset_password: " . $e->getMessage());
        fp_error(500, 'Error al actualizar la contraseña.');
    }
}
