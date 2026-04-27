<?php
/**
 * FitPaisa — Endpoint de Gestión de Perfiles (Consolidado)
 *
 * CRUD del perfil físico del usuario autenticado.
 * Algoritmo de macros estilo Fitia: Déficit/Superávit exacto por peso objetivo.
 *
 * Esta versión es MONOLÍTICA para evitar errores 500 causados por el sistema 
 * de archivos de Vercel al procesar múltiples 'require_once'.
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 * @version  2.0.0 (Monolithic)
 */

declare(strict_types=1);

/* ══════════════════════════════════════════════════════════════════════
   CORE HELPERS (Inyectados de _db.php y _jwt.php)
   ══════════════════════════════════════════════════════════════════════ */

$_fp_pdo = null;

function fp_db(): PDO
{
    global $_fp_pdo;
    if ($_fp_pdo instanceof PDO) return $_fp_pdo;

    $env = getenv('VERCEL_ENV') ?: 'local';
    
    // Resolución de credenciales con prioridad DB_PASSWORD_NUEVA
    if ($env === 'production') {
        $host = getenv('PGHOST_PROD')     ?: getenv('POSTGRES_HOST');
        $user = getenv('PGUSER_PROD')     ?: getenv('POSTGRES_USER');
        $pass = getenv('DB_PASSWORD_NUEVA') ?: getenv('PGPASSWORD_PROD') ?: getenv('POSTGRES_PASSWORD');
        $db   = getenv('PGDATABASE_PROD') ?: 'neondb';
        $port = getenv('PGPORT')          ?: '5432';
    } else {
        $host = getenv('PGHOST')          ?: getenv('POSTGRES_HOST');
        $user = getenv('PGUSER')          ?: getenv('POSTGRES_USER');
        $pass = getenv('DB_PASSWORD_NUEVA') ?: getenv('PGPASSWORD') ?: getenv('POSTGRES_PASSWORD');
        $db   = getenv('PGDATABASE')      ?: 'fitpaisa_testing';
        $port = getenv('PGPORT')          ?: '5432';
        if ($env === 'preview' || empty(getenv('PGDATABASE'))) $db = 'fitpaisa_testing';
    }

    $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";

    try {
        $_fp_pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
    } catch (PDOException $e) {
        error_log('[FitPaisa][DB] Fallo de conexión: ' . $e->getMessage());
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos.']);
        exit;
    }

    return $_fp_pdo;
}

function fp_query(string $sql, array $params = []): PDOStatement
{
    try {
        $stmt = fp_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log('[FitPaisa][QUERY] ' . $e->getMessage() . ' | SQL: ' . $sql);
        fp_error(500, 'Error interno en la consulta.');
    }
}

function fp_error(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8')], JSON_UNESCAPED_UNICODE);
    exit;
}

function fp_success(array $data = [], int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function fp_sanitize(mixed $value, int $maxLen = 255, string $type = 'string'): mixed
{
    $val = trim((string) ($value ?? ''));
    switch ($type) {
        case 'int': return (int)$val;
        case 'float': return (float)$val;
        case 'slug': $val = preg_replace('/[^a-z0-9\-_]/', '', strtolower($val)); break;
        default: $val = strip_tags($val); $val = htmlspecialchars($val, ENT_QUOTES | ENT_HTML5, 'UTF-8'); break;
    }
    return ($maxLen > 0) ? mb_substr($val, 0, $maxLen) : $val;
}

function fp_json_body(): array
{
    $raw = file_get_contents('php://input');
    return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
}

function fp_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    header("Access-Control-Allow-Origin: " . ($origin ?: '*'));
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

/* ══════════════════════════════════════════════════════════════════════
   JWT LOGIC (Inyectada de _jwt.php)
   ══════════════════════════════════════════════════════════════════════ */

function jwt_require(): array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authHeader, 'Bearer ')) {
        fp_error(401, 'No autenticado. Inicia sesión.');
    }
    $token = substr($authHeader, 7);
    $parts = explode('.', $token);
    if (count($parts) !== 3) fp_error(401, 'Token inválido.');

    [$header, $body, $signature] = $parts;
    $secret = getenv('JWT_SECRET');
    if (!$secret || strlen($secret) < 32) fp_error(500, 'Security configuration error.');

    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$body}", $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $signature)) fp_error(401, 'Firma de token inválida.');

    $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    if (!isset($payload['exp']) || $payload['exp'] < time()) fp_error(401, 'Token expirado.');

    return $payload;
}

/* ══════════════════════════════════════════════════════════════════════
   ROUTER & ACTIONS
   ══════════════════════════════════════════════════════════════════════ */

fp_cors();

if (strpos($_SERVER['SCRIPT_NAME'], 'profile.php') !== false) {
    $payload = jwt_require();
    $action = fp_sanitize($_GET['action'] ?? 'get', 32, 'slug');
    
    match ($action) {
        'get'          => handle_get_profile($payload),
        'update', 'save' => handle_update_profile($payload),
        'setup_macros' => handle_setup_macros($payload),
        'log_body'     => handle_log_body($payload),
        'history'      => handle_body_history($payload),
        default        => fp_error(400, "Acción '{$action}' no reconocida."),
    };
}

function handle_get_profile(array $payload): never
{
    $profile = fp_query(
        "SELECT p.*, u.full_name, u.email, u.phone, s.plan_type AS subscription_plan, s.status AS subscription_status
         FROM profiles p
         JOIN users u ON u.user_id = p.user_id
         LEFT JOIN subscriptions s ON s.user_id = p.user_id AND s.status = 'ACTIVE'
         WHERE p.user_id = :uid LIMIT 1",
        [':uid' => $payload['user_id']]
    )->fetch();

    if (!$profile) fp_error(404, 'Perfil no encontrado.');

    $macros = calculate_macros(
        (float)$profile['weight'], (float)$profile['height'], (int)$profile['age'],
        $profile['gender'], $profile['objective'], $profile['activity_level'],
        (float)($profile['target_weight'] ?? 0), (int)($profile['target_time_weeks'] ?? 0)
    );

    fp_success(['profile' => $profile, 'macro_targets' => $macros]);
}

function handle_update_profile(array $payload): never
{
    $body   = fp_json_body();
    $userId = $payload['user_id'];

    $current = fp_query('SELECT * FROM profiles WHERE user_id = :uid', [':uid' => $userId])->fetch() ?: [];

    $weight = isset($body['weight']) ? (float)$body['weight'] : (float)($current['weight'] ?? 70);
    $height = isset($body['height']) ? (float)$body['height'] : (float)($current['height'] ?? 170);
    $age    = isset($body['age'])    ? (int)$body['age']     : (int)($current['age'] ?? 30);

    $gender    = $body['gender']         ?? $current['gender']         ?? 'OTHER';
    $objective = $body['objective']      ?? $current['objective']      ?? 'MAINTAIN';
    $activity  = $body['activity_level'] ?? $current['activity_level'] ?? 'MODERATE';
    $targetWeight = isset($body['target_weight']) ? (float)$body['target_weight'] : (float)($current['target_weight'] ?? $weight);
    $weeks        = isset($body['target_time_weeks']) ? (int)$body['target_time_weeks'] : (int)($current['target_time_weeks'] ?? 0);
    $timezone     = $body['timezone'] ?? $current['timezone'] ?? null;

    if ($weight <= 0 || $height <= 0 || $age <= 0) fp_error(400, 'Datos físicos inválidos.');

    fp_query(
        "INSERT INTO profiles (user_id, weight, height, age, gender, objective, activity_level, target_weight, target_time_weeks, timezone, updated_at)
         VALUES (:uid, :w, :h, :a, :g, :o, :al, :tw, :ttw, :tz, NOW())
         ON CONFLICT (user_id) DO UPDATE SET
            weight = EXCLUDED.weight, height = EXCLUDED.height, age = EXCLUDED.age, gender = EXCLUDED.gender,
            objective = EXCLUDED.objective, activity_level = EXCLUDED.activity_level,
            target_weight = EXCLUDED.target_weight, target_time_weeks = EXCLUDED.target_time_weeks,
            timezone = COALESCE(EXCLUDED.timezone, profiles.timezone), updated_at = NOW()",
        [':uid'=>$userId, ':w'=>$weight, ':h'=>$height, ':a'=>$age, ':g'=>$gender, ':o'=>$objective, ':al'=>$activity, ':tw'=>$targetWeight, ':ttw'=>$weeks, ':tz'=>$timezone]
    );

    // Sincronizar peso con historial (body_logs)
    if ($weight > 0) {
        $pid = fp_query('SELECT profile_id FROM profiles WHERE user_id = :uid', [':uid' => $userId])->fetchColumn();
        if ($pid) {
            fp_query(
                "INSERT INTO body_logs (profile_id, weight, log_date) 
                 VALUES (:pid, :w, CURRENT_DATE)
                 ON CONFLICT (profile_id, log_date) DO UPDATE SET weight = EXCLUDED.weight",
                [':pid' => $pid, ':w' => $weight]
            );
        }
    }

    $macros = calculate_macros($weight, $height, $age, $gender, $objective, $activity, $targetWeight, $weeks);
    $profile = fp_query('SELECT * FROM profiles WHERE user_id = :uid', [':uid' => $userId])->fetch();

    fp_success(['message' => 'Perfil actualizado correctamente.', 'macro_targets' => $macros, 'profile' => $profile]);
}

function handle_setup_macros(array $payload): never
{
    $body = fp_json_body();
    $w = (float)($body['weight'] ?? 0);
    $h = (float)($body['height'] ?? 0);
    $a = (int)($body['age'] ?? 0);
    $obj = $body['objective'] ?? 'MAINTAIN';
    $tw = (float)($body['target_weight'] ?? $w);
    $weeks = (int)($body['target_time_weeks'] ?? 0);

    if ($w <= 0 || $h <= 0 || $a <= 0) fp_error(400, 'Datos inválidos.');

    // Gender and activity are NOT NULL, so we need defaults for the initial insert
    $gender = $body['gender'] ?? 'OTHER';
    $activity = $body['activity_level'] ?? 'MODERATE';

    fp_query(
        "INSERT INTO profiles (user_id, weight, height, age, gender, activity_level, objective, target_weight, target_time_weeks, updated_at)
         VALUES (:uid, :w, :h, :a, :g, :al, :o, :tw, :ttw, NOW())
         ON CONFLICT (user_id) DO UPDATE SET
            weight=EXCLUDED.weight, height=EXCLUDED.height, age=EXCLUDED.age, 
            objective=EXCLUDED.objective, target_weight=EXCLUDED.target_weight, 
            target_time_weeks=EXCLUDED.target_time_weeks, updated_at=NOW()",
        [':uid'=>$payload['user_id'], ':w'=>$w, ':h'=>$h, ':a'=>$a, ':g'=>$gender, ':al'=>$activity, ':o'=>$obj, ':tw'=>$tw, ':ttw'=>$weeks]
    );

    // Sincronizar con historial de medidas (body_logs)
    $pid = fp_query('SELECT profile_id FROM profiles WHERE user_id = :uid', [':uid' => $payload['user_id']])->fetchColumn();
    if ($pid) {
        fp_query(
            "INSERT INTO body_logs (profile_id, weight, log_date) 
             VALUES (:pid, :w, CURRENT_DATE)
             ON CONFLICT (profile_id, log_date) DO UPDATE SET weight = EXCLUDED.weight",
            [':pid' => $pid, ':w' => $w]
        );
    }

    $gender = fp_query('SELECT gender FROM profiles WHERE user_id=:uid',[':uid'=>$payload['user_id']])->fetchColumn() ?: 'OTHER';
    $activity = fp_query('SELECT activity_level FROM profiles WHERE user_id=:uid',[':uid'=>$payload['user_id']])->fetchColumn() ?: 'MODERATE';

    $macros = calculate_macros($w, $h, $a, $gender, $obj, $activity, $tw, $weeks);
    fp_success(['message' => 'Configurado.', 'macro_targets' => $macros]);
}

function handle_log_body(array $payload): never
{
    $body = fp_json_body();
    $w = (float)($body['weight'] ?? 0);
    if ($w <= 0) fp_error(400, 'Peso inválido.');

    $pid = fp_query('SELECT profile_id FROM profiles WHERE user_id = :uid', [':uid' => $payload['user_id']])->fetchColumn();
    if (!$pid) fp_error(404, 'Perfil no encontrado.');

    fp_query("INSERT INTO body_logs (profile_id, weight, log_date) VALUES (:pid, :w, CURRENT_DATE)", [':pid'=>$pid, ':w'=>$w]);
    fp_query("UPDATE profiles SET weight = :w, updated_at = NOW() WHERE profile_id = :pid", [':w'=>$w, ':pid'=>$pid]);
    fp_success(['message' => 'Registrado.']);
}

function handle_body_history(array $payload): never
{
    $days = (int)($_GET['days'] ?? 30);
    $rows = fp_query(
        "SELECT bl.log_date, bl.weight FROM body_logs bl JOIN profiles p ON p.profile_id = bl.profile_id
         WHERE p.user_id = :uid AND bl.log_date >= CURRENT_DATE - :days * INTERVAL '1 day' ORDER BY bl.log_date ASC",
        [':uid' => $payload['user_id'], ':days' => $days]
    )->fetchAll();
    fp_success(['history' => $rows]);
}

function calculate_macros($weight, $height, $age, $gender, $objective, $activity, $targetWeight=0, $weeks=0): array 
{
    $genderConst = ($gender === 'FEMALE') ? -161 : 5;
    $tmb = (10 * $weight) + (6.25 * $height) - (5 * $age) + $genderConst;
    $factors = ['SEDENTARY'=>1.2, 'LIGHT'=>1.375, 'MODERATE'=>1.55, 'ACTIVE'=>1.725, 'VERY_ACTIVE'=>1.9];
    $tdee = $tmb * ($factors[$activity] ?? 1.55);
    $adj = match($objective) { 'LOSE_WEIGHT'=>-0.20, 'GAIN_MUSCLE'=>0.10, default=>0.00 };
    $cal = max(1200, $tdee * (1 + $adj));
    $prot = $weight * 2.0;
    $fat = ($cal * 0.25) / 9;
    $carb = ($cal - ($prot*4 + $fat*9)) / 4;
    return ['calories' => (int)round($cal), 'protein_g' => (int)round($prot), 'carbs_g' => (int)round($carb), 'fat_g' => (int)round($fat), 'tmb' => (int)round($tmb), 'tdee' => (int)round($tdee), 'adjustment' => (int)round($cal - $tdee)];
}
