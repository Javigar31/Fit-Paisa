<?php
/**
 * FitPaisa — Endpoint de Entrenamientos (Consolidado)
 *
 * Gestión completa de planes de entrenamiento y ejercicios.
 * Los coaches pueden crear/aprobar planes; los usuarios los consultan.
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
        echo json_encode(['success' => false, 'message' => 'Error de conexión.']);
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
        fp_error(500, 'Error interno.');
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

function jwt_require(): array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authHeader, 'Bearer ')) fp_error(401, 'No autenticado.');
    $token = substr($authHeader, 7);
    $parts = explode('.', $token);
    if (count($parts) !== 3) fp_error(401, 'Token inválido.');

    [$h, $b, $s] = $parts;
    $secret = getenv('JWT_SECRET');
    if (!$secret) fp_error(500, 'Security error.');

    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$h}.{$b}", $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $s)) fp_error(401, 'Firma inválida.');

    $payload = json_decode(base64_decode(strtr($b, '-_', '+/')), true);
    if (!isset($payload['exp']) || $payload['exp'] < time()) fp_error(401, 'Token expirado.');
    return $payload;
}

/* ══════════════════════════════════════════════════════════════════════
   ROUTER & ACTIONS
   ══════════════════════════════════════════════════════════════════════ */

fp_cors();
$payload = jwt_require();
$action  = fp_sanitize($_GET['action'] ?? 'my_plans', 32, 'slug');

match ($action) {
    'my_plans'     => handle_my_plans($payload),
    'plan'         => handle_get_plan($payload),
    'create_plan'  => handle_create_plan($payload),
    'add_exercise' => handle_add_exercise($payload),
    'approve'      => handle_approve_plan($payload),
    'records'      => handle_personal_records($payload),
    default        => fp_error(400, "Action '{$action}' error."),
};

function handle_my_plans(array $payload): never
{
    $plans = fp_query(
        "SELECT wp.plan_id, wp.name, wp.status, wp.start_date, wp.end_date, wp.created_at, u.full_name AS coach_name
         FROM workout_plans wp LEFT JOIN users u ON u.user_id = wp.coach_id
         WHERE wp.user_id = :uid ORDER BY wp.created_at DESC",
        [':uid' => $payload['user_id']]
    )->fetchAll();
    fp_success(['plans' => $plans]);
}

function handle_get_plan(array $payload): never
{
    $pid = (int)($_GET['id'] ?? 0);
    if ($pid <= 0) fp_error(400, 'ID inválido.');

    $plan = fp_query("SELECT wp.*, u.full_name AS coach_name FROM workout_plans wp LEFT JOIN users u ON u.user_id = wp.coach_id WHERE wp.plan_id = :pid AND (wp.user_id = :uid OR wp.coach_id = :uid)", [':pid'=>$pid, ':uid'=>$payload['user_id']])->fetch();
    if (!$plan) fp_error(404, 'No encontrado.');

    $exercises = fp_query("SELECT * FROM exercises WHERE plan_id = :pid ORDER BY day_of_week, exercise_id", [':pid' => $pid])->fetchAll();
    fp_success(['plan' => $plan, 'exercises' => $exercises]);
}

function handle_create_plan(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fp_error(405, 'Método no permitido.');
    if (!in_array($payload['role']??'', ['COACH', 'ADMIN'])) fp_error(403, 'Solo coaches.');

    $body = fp_json_body();
    $uid = (int)($body['user_id'] ?? 0);
    $name = fp_sanitize($body['name'] ?? '', 200);
    if ($uid <= 0 || empty($name)) fp_error(400, 'Datos incompletos.');

    $stmt = fp_query(
        "INSERT INTO workout_plans (coach_id, user_id, name, status, start_date)
         VALUES (:cid, :uid, :name, 'DRAFT', CURRENT_DATE) RETURNING plan_id",
        [':cid' => $payload['user_id'], ':uid' => $uid, ':name' => $name]
    );

    fp_success(['message' => 'Plan creado.', 'plan_id' => $stmt->fetchColumn()], 201);
}

function handle_add_exercise(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fp_error(405, 'Método no permitido.');
    $body = fp_json_body();
    $pid = (int)($body['plan_id'] ?? 0);
    $plan = fp_query('SELECT coach_id, status FROM workout_plans WHERE plan_id = :pid', [':pid' => $pid])->fetch();

    if (!$plan) fp_error(404, 'No encontrado.');
    if ($plan['coach_id'] != $payload['user_id'] && $payload['role'] !== 'ADMIN') fp_error(403, 'No tienes permiso.');

    $stmt = fp_query(
        "INSERT INTO exercises (plan_id, name, sets, reps, load_kg, rest_seconds, day_of_week, notes)
         VALUES (:pid, :name, :sets, :reps, :load, :rest, :day, :notes) RETURNING exercise_id",
        [':pid'=>$pid, ':name'=>$body['name'], ':sets'=>(int)$body['sets'], ':reps'=>(int)$body['reps'], ':load'=>(float)($body['load_kg']??0), ':rest'=>(int)($body['rest_seconds']??60), ':day'=>$body['day_of_week'], ':notes'=>$body['notes']??null]
    );
    fp_success(['message' => 'Ejercicio añadido.', 'exercise_id' => $stmt->fetchColumn()], 201);
}

function handle_approve_plan(array $payload): never
{
    if (!in_array($payload['role']??'', ['COACH', 'ADMIN'])) fp_error(403, 'Solo coaches.');
    $pid = (int)(fp_json_body()['plan_id'] ?? 0);
    fp_query("UPDATE workout_plans SET status = 'ACTIVE' WHERE plan_id = :pid", [':pid' => $pid]);
    fp_success(['message' => 'Plan activado.']);
}

function handle_personal_records(array $payload): never
{
    $rows = fp_query(
        "SELECT e.name AS exercise_name, MAX(e.load_kg) AS max_load_kg
         FROM exercises e JOIN workout_plans wp ON wp.plan_id = e.plan_id
         WHERE wp.user_id = :uid GROUP BY e.name ORDER BY max_load_kg DESC",
        [':uid' => $payload['user_id']]
    )->fetchAll();
    fp_success(['records' => $rows]);
}
