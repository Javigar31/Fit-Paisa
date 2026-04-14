<?php
/**
 * FitPaisa — Endpoint de Nutrición (Consolidado)
 *
 * Registro y consulta de ingestas diarias de alimentos.
 * Calcula automáticamente calorías y macronutrientes proporcionalmente.
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
   CORE HELPERS (Inyectados para autonomía total)
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
    } else {
        $host = getenv('PGHOST')          ?: getenv('POSTGRES_HOST');
        $user = getenv('PGUSER')          ?: getenv('POSTGRES_USER');
        $pass = getenv('DB_PASSWORD_NUEVA') ?: getenv('PGPASSWORD') ?: getenv('POSTGRES_PASSWORD');
        $db   = getenv('PGDATABASE')      ?: 'fitpaisa_testing';
        if ($env === 'preview' || empty(getenv('PGDATABASE'))) $db = 'fitpaisa_testing';
    }

    $dsn = "pgsql:host={$host};port=5432;dbname={$db};sslmode=require";

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

/* ══════════════════════════════════════════════════════════════════════
   ROUTER & ACTIONS
   ══════════════════════════════════════════════════════════════════════ */

fp_cors();
$payload = jwt_require();
$action = trim($_GET['action'] ?? 'daily');

match ($action) {
    'log'     => handle_log_food($payload),
    'daily'   => handle_daily_log($payload),
    'summary' => handle_daily_summary($payload),
    'weekly'  => handle_weekly_trend($payload),
    'edit'    => handle_edit_entry($payload),
    'delete'  => handle_delete_entry($payload),
    default   => fp_error(400, "Action error: [{$action}]."),
};

function handle_log_food(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fp_error(405, 'Método no permitido.');
    $body = fp_json_body();
    $foodName = fp_sanitize($body['food_name'] ?? '', 200);
    $portion = (float)($body['portion_grams'] ?? 0);
    if ($portion <= 0 || empty($foodName)) fp_error(400, 'Datos incompletos.');

    $pid = fp_query('SELECT profile_id FROM profiles WHERE user_id = :uid', [':uid' => $payload['user_id']])->fetchColumn();
    if (!$pid) fp_error(404, 'Perfil no encontrado.');

    fp_query(
        "INSERT INTO food_entries (profile_id, food_name, portion_grams, calories, protein, carbs, fat, meal_type, log_date, portion_amount, portion_unit, unit_size)
         VALUES (:pid, :fn, :pg, :cal, :pro, :car, :fat, :mt, :ld, :pa, :pu, :us)",
        [
            ':pid' => $pid, ':fn' => $foodName, ':pg' => $portion,
            ':cal' => (float)($body['calories'] ?? 0), ':pro' => (float)($body['protein'] ?? 0),
            ':car' => (float)($body['carbs'] ?? 0), ':fat' => (float)($body['fat'] ?? 0),
            ':mt'  => $body['meal_type'] ?? 'SNACK', ':ld' => $body['log_date'] ?? date('Y-m-d'),
            ':pa'  => $body['portion_amount'] ?? null, ':pu' => $body['portion_unit'] ?? null, ':us' => $body['unit_size'] ?? null
        ]
    );
    fp_success(['message' => 'Ingesta registrada.'], 201);
}

function handle_daily_log(array $payload): never
{
    $date = fp_sanitize($_GET['date'] ?? date('Y-m-d'), 10);
    $entries = fp_query(
        "SELECT fe.* FROM food_entries fe JOIN profiles p ON p.profile_id = fe.profile_id
         WHERE p.user_id = :uid AND fe.log_date = :date ORDER BY fe.created_at ASC",
        [':uid' => $payload['user_id'], ':date' => $date]
    )->fetchAll();
    fp_success(['entries' => $entries, 'date' => $date]);
}

function handle_daily_summary(array $payload): never
{
    $date = fp_sanitize($_GET['date'] ?? date('Y-m-d'), 10);
    $sums = fp_query(
        "SELECT COALESCE(SUM(fe.calories), 0) AS total_calories, COALESCE(SUM(fe.protein), 0) AS total_protein,
                COALESCE(SUM(fe.carbs), 0) AS total_carbs, COALESCE(SUM(fe.fat), 0) AS total_fat
         FROM food_entries fe JOIN profiles p ON p.profile_id = fe.profile_id
         WHERE p.user_id = :uid AND fe.log_date = :date",
        [':uid' => $payload['user_id'], ':date' => $date]
    )->fetch();

    $profile = fp_query('SELECT weight, height, age, gender, objective, activity_level, target_weight, target_time_weeks FROM profiles WHERE user_id = :uid', [':uid' => $payload['user_id']])->fetch();
    
    $targets = $profile ? calculate_macros((float)$profile['weight'], (float)$profile['height'], (int)$profile['age'], $profile['gender'], $profile['objective'], $profile['activity_level'], (float)$profile['target_weight'], (int)$profile['target_time_weeks']) : null;

    fp_success(['date' => $date, 'totals' => $sums, 'targets' => $targets]);
}

function handle_weekly_trend(array $payload): never
{
    $rows = fp_query(
        "SELECT fe.log_date, ROUND(SUM(fe.calories)::numeric, 0) AS total_calories
         FROM food_entries fe JOIN profiles p ON p.profile_id = fe.profile_id
         WHERE p.user_id = :uid AND fe.log_date >= CURRENT_DATE - INTERVAL '7 days'
         GROUP BY fe.log_date ORDER BY fe.log_date ASC",
        [':uid' => $payload['user_id']]
    )->fetchAll();
    fp_success(['weekly_trend' => $rows]);
}

function handle_edit_entry(array $payload): never
{
    $body = fp_json_body();
    $eid = (int)($body['entry_id'] ?? 0);
    $owned = fp_query('SELECT 1 FROM food_entries fe JOIN profiles p ON p.profile_id = fe.profile_id WHERE fe.entry_id = :eid AND p.user_id = :uid', [':eid'=>$eid, ':uid'=>$payload['user_id']])->fetchColumn();
    if (!$owned) fp_error(403, 'No permitido.');

    fp_query(
        "UPDATE food_entries SET food_name=:fn, portion_grams=:pg, calories=:cal, protein=:pro, carbs=:car, fat=:fat, portion_amount=:pa, portion_unit=:pu, unit_size=:us WHERE entry_id=:eid",
        [':fn'=>$body['food_name'], ':pg'=>(float)$body['portion_grams'], ':cal'=>(float)$body['calories'], ':pro'=>(float)$body['protein'], ':car'=>(float)$body['carbs'], ':fat'=>(float)$body['fat'], ':pa'=>$body['portion_amount']??null, ':pu'=>$body['portion_unit']??null, ':us'=>$body['unit_size']??null, ':eid'=>$eid]
    );
    fp_success(['message' => 'Actualizado.']);
}

function handle_delete_entry(array $payload): never
{
    $eid = (int)($_GET['entry_id'] ?? fp_json_body()['entry_id'] ?? 0);
    $owned = fp_query('SELECT 1 FROM food_entries WHERE entry_id=:eid AND profile_id=(SELECT profile_id FROM profiles WHERE user_id=:uid)', [':eid'=>$eid, ':uid'=>$payload['user_id']])->fetchColumn();
    if (!$owned) fp_error(404, 'No encontrado.');
    fp_query('DELETE FROM food_entries WHERE entry_id = :eid', [':eid' => $eid]);
    fp_success(['message' => 'Eliminado.']);
}
