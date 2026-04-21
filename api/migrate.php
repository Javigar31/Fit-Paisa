<?php
/**
 * FitPaisa — Endpoint de Migración de Base de Datos (Manual)
 *
 * Reemplaza la auto-migración que se ejecutaba en cada request.
 * Debe ejecutarse UNA SOLA VEZ después de cambios de esquema.
 *
 * Seguridad: Requiere el token DIAG_TOKEN como query param.
 * Uso: GET /api/migrate.php?token=TU_TOKEN_SECRETO
 *
 * @version 6.3.0
 */

declare(strict_types=1);
require_once __DIR__ . '/_db.php';

// -----------------------------------------------------------------
// 1. Seguridad: Solo accesible con token correcto
// -----------------------------------------------------------------
$expected_token = getenv('DIAG_TOKEN');
$provided_token = $_GET['token'] ?? '';

if (empty($expected_token) || $provided_token !== $expected_token) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Acceso denegado.']);
    exit;
}

// -----------------------------------------------------------------
// 2. Ejecutar Migraciones
// -----------------------------------------------------------------
$db = fp_db();
$results = [];
$errors  = [];

// Paso 0: Crear tablas base (necesario en BD nueva)
try {
    fp_ensure_schema();
    $results[] = ['status' => 'OK', 'sql' => 'fp_ensure_schema(): tablas base creadas...'];
} catch (Throwable $e) {
    $errors[] = ['status' => 'ERROR', 'sql' => 'fp_ensure_schema()', 'error' => $e->getMessage()];
}

$migrations = [
    // Profiles
    "ALTER TABLE profiles ADD COLUMN IF NOT EXISTS target_weight DECIMAL(5,2)",
    "ALTER TABLE profiles ADD COLUMN IF NOT EXISTS target_time_weeks SMALLINT",
    "ALTER TABLE profiles ADD COLUMN IF NOT EXISTS timezone VARCHAR(50)",

    // food_catalog
    "ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS unit_name VARCHAR(50)",
    "ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_std DECIMAL(5,2)",
    "ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_small DECIMAL(5,2)",
    "ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_medium DECIMAL(5,2)",
    "ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_large DECIMAL(5,2)",
    "ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS is_liquid BOOLEAN DEFAULT FALSE",

    // food_entries
    "ALTER TABLE food_entries ADD COLUMN IF NOT EXISTS portion_amount DECIMAL(10,2) DEFAULT 100",
    "ALTER TABLE food_entries ADD COLUMN IF NOT EXISTS portion_unit VARCHAR(50) DEFAULT 'g'",
    "ALTER TABLE food_entries ADD COLUMN IF NOT EXISTS unit_size VARCHAR(20)",

    // subscriptions
    "ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ DEFAULT NOW()",
    "ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS starts_at TIMESTAMPTZ",
    "ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS ends_at TIMESTAMPTZ",
    "ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS provider VARCHAR(50)",
    "UPDATE subscriptions SET starts_at = start_date::timestamptz WHERE starts_at IS NULL AND start_date IS NOT NULL",
    "UPDATE subscriptions SET ends_at = end_date::timestamptz WHERE ends_at IS NULL AND end_date IS NOT NULL",

    // Datos de alimentos
    "UPDATE food_catalog SET unit_name = 'Huevo', weight_std = 50, weight_small = 40, weight_medium = 50, weight_large = 60 WHERE (name ILIKE '%huevo%entero%' OR name = 'Huevo') AND unit_name IS NULL",
    "UPDATE food_catalog SET unit_name = 'Rebanada', weight_std = 30 WHERE name ILIKE '%pan%' AND unit_name IS NULL",
    "UPDATE food_catalog SET unit_name = 'Ala', weight_std = 35 WHERE name ILIKE '%alita%' AND unit_name IS NULL",
    "UPDATE food_catalog SET unit_name = 'Yema', weight_std = 17 WHERE name ILIKE '%yema%' AND unit_name IS NULL",
    "UPDATE food_catalog SET is_liquid = TRUE WHERE (name ILIKE '%leche%' OR name ILIKE '%aceite%' OR name ILIKE '%vino%' OR name ILIKE '%bebida%' OR name ILIKE '%zumo%') AND is_liquid = FALSE",

    // Índices de rendimiento
    "CREATE INDEX IF NOT EXISTS idx_users_role_status ON users(role, is_active)",
    "CREATE INDEX IF NOT EXISTS idx_users_created_at_desc ON users(created_at DESC)",
    "CREATE INDEX IF NOT EXISTS idx_subs_status_plan ON subscriptions(status, plan_type)",
    "CREATE INDEX IF NOT EXISTS idx_subs_updated_at ON subscriptions(updated_at, status)",
    "CREATE INDEX IF NOT EXISTS idx_wp_coach_status ON workout_plans(coach_id, status)",
    "CREATE INDEX IF NOT EXISTS idx_subs_user_id ON subscriptions(user_id)",

    // Tablas de sistema
    "CREATE TABLE IF NOT EXISTS rate_limits (rate_key VARCHAR(255) PRIMARY KEY, hits INTEGER DEFAULT 1, reset_at TIMESTAMPTZ NOT NULL)",
    "CREATE TABLE IF NOT EXISTS password_resets (email VARCHAR(150) NOT NULL REFERENCES users(email) ON DELETE CASCADE, code VARCHAR(6) NOT NULL, expires_at TIMESTAMPTZ NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), PRIMARY KEY (email))",
];

foreach ($migrations as $sql) {
    try {
        $db->exec($sql);
        $results[] = ['status' => 'OK', 'sql' => substr($sql, 0, 80) . '...'];
    } catch (PDOException $e) {
        $errors[] = ['status' => 'ERROR', 'sql' => substr($sql, 0, 80) . '...', 'error' => $e->getMessage()];
    }
}

// -----------------------------------------------------------------
// 3. Respuesta
// -----------------------------------------------------------------
$env_info = fp_env_info();
$status   = empty($errors) ? 'COMPLETADO SIN ERRORES' : 'COMPLETADO CON ERRORES';

http_response_code(empty($errors) ? 200 : 207);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success'        => empty($errors),
    'status'         => $status,
    'environment'    => $env_info['env'],
    'database'       => $env_info['database'],
    'total_ok'       => count($results),
    'total_errors'   => count($errors),
    'results'        => $results,
    'errors'         => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
