<?php
/**
 * FitPaisa — Endpoint de Nutrición
 *
 * Registro y consulta de ingestas diarias de alimentos.
 * Calcula automáticamente calorías y macronutrientes proporcionalmente.
 *
 * Rutas:
 *   POST /api/nutrition.php?action=log        → Registrar una ingesta
 *   GET  /api/nutrition.php?action=daily      → Ingestas del día (?date=YYYY-MM-DD)
 *   GET  /api/nutrition.php?action=summary    → Resumen de macros del día vs objetivo
 *   GET  /api/nutrition.php?action=weekly     → Tendencia calórica semanal
 *   DELETE /api/nutrition.php?action=delete   → Eliminar ingesta (?entry_id=N)
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_jwt.php';
require_once __DIR__ . '/profile.php';   /* Reutiliza calculate_macros() */

fp_cors();

$payload = jwt_require();
$action = trim($_GET['action'] ?? 'daily');

if ($action === 'log') {
    handle_log_food($payload);
} elseif ($action === 'daily') {
    handle_daily_log($payload);
} elseif ($action === 'summary') {
    handle_daily_summary($payload);
} elseif ($action === 'weekly') {
    handle_weekly_trend($payload);
} elseif ($action === 'edit') {
    handle_edit_entry($payload);
} elseif ($action === 'delete') {
    handle_delete_entry($payload);
} else {
    fp_error(400, "Action error: [{$action}]. Try 'daily' or 'summary'.");
}

/* ══════════════════════════════════════════════════════════════════════
   REGISTRAR INGESTA
   ══════════════════════════════════════════════════════════════════════ */
function handle_log_food(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }

    $body      = fp_json_body();
    $foodName  = fp_sanitize($body['food_name']     ?? '', 200);
    $portion   = fp_sanitize($body['portion_grams']    ?? 0, 0, 'float');
    $calories  = fp_sanitize($body['calories']         ?? 0, 0, 'float');
    $protein   = fp_sanitize($body['protein']          ?? 0, 0, 'float');
    $carbs     = fp_sanitize($body['carbs']            ?? 0, 0, 'float');
    $fat       = fp_sanitize($body['fat']              ?? 0, 0, 'float');
    $mealType  = fp_sanitize($body['meal_type']     ?? 'SNACK', 20, 'slug');
    $logDate   = fp_sanitize($body['log_date']      ?? date('Y-m-d'), 10);
    
    // Metadatos de unidades
    $pAmount   = isset($body['portion_amount']) ? fp_sanitize($body['portion_amount'], 0, 'float') : null;
    $pUnit     = isset($body['portion_unit'])   ? fp_sanitize($body['portion_unit'], 50) : null;
    $uSize     = isset($body['unit_size'])     ? fp_sanitize($body['unit_size'], 20, 'slug') : null;

    $errors = [];
    if (empty($foodName))   $errors[] = 'El nombre del alimento es obligatorio.';
    if ($portion   <= 0)    $errors[] = 'La porción debe ser mayor a 0.';
    if ($calories  < 0)     $errors[] = 'Las calorías no pueden ser negativas.';
    if (!in_array($mealType, ['BREAKFAST', 'LUNCH', 'DINNER', 'SNACK'], true))
        $errors[] = 'Tipo de comida inválido.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate))
        $errors[] = 'Fecha inválida (formato YYYY-MM-DD).';

    if (!empty($errors)) {
        fp_error(400, implode(' | ', $errors));
    }

    $profileId = fp_query(
        'SELECT profile_id FROM profiles WHERE user_id = :uid',
        [':uid' => $payload['user_id']]
    )->fetchColumn();

    if (!$profileId) {
        fp_error(404, 'Perfil no encontrado.');
    }

    $stmt = fp_query(
        'INSERT INTO food_entries
            (profile_id, food_name, portion_grams, calories, protein, carbs, fat, meal_type, log_date, portion_amount, portion_unit, unit_size)
         VALUES (:pid, :fn, :pg, :cal, :pro, :car, :fat, :mt, :ld, :pa, :pu, :us)
         RETURNING entry_id',
        [
            ':pid' => $profileId,
            ':fn'  => $foodName,
            ':pg'  => $portion,
            ':cal' => $calories,
            ':pro' => $protein,
            ':car' => $carbs,
            ':fat' => $fat,
            ':mt'  => $mealType,
            ':ld'  => $logDate,
            ':pa'  => $pAmount,
            ':pu'  => $pUnit,
            ':us'  => $uSize,
        ]
    );

    $entryId = $stmt->fetchColumn();
    fp_success(['message' => 'Ingesta registrada.', 'entry_id' => $entryId], 201);
}

/* ══════════════════════════════════════════════════════════════════════
   LOG DIARIO
   ══════════════════════════════════════════════════════════════════════ */
function handle_daily_log(array $payload): never
{
    $date = fp_sanitize($_GET['date'] ?? date('Y-m-d'), 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fp_error(400, 'Fecha inválida.');
    }

    $entries = fp_query(
        'SELECT fe.*
         FROM food_entries fe
         JOIN profiles p ON p.profile_id = fe.profile_id
         WHERE p.user_id = :uid AND fe.log_date = :date
         ORDER BY fe.created_at ASC',
        [':uid' => $payload['user_id'], ':date' => $date]
    )->fetchAll();

    fp_success(['entries' => $entries, 'date' => $date]);
}

/* ══════════════════════════════════════════════════════════════════════
   RESUMEN DIARIO vs OBJETIVO
   ══════════════════════════════════════════════════════════════════════ */
function handle_daily_summary(array $payload): never
{
    $date = fp_sanitize($_GET['date'] ?? date('Y-m-d'), 10);

    $sums = fp_query(
        'SELECT
            COALESCE(SUM(fe.calories), 0) AS total_calories,
            COALESCE(SUM(fe.protein),  0) AS total_protein,
            COALESCE(SUM(fe.carbs),    0) AS total_carbs,
            COALESCE(SUM(fe.fat),      0) AS total_fat,
            COUNT(*)                       AS entries_count
         FROM food_entries fe
         JOIN profiles p ON p.profile_id = fe.profile_id
         WHERE p.user_id = :uid AND fe.log_date = :date',
        [':uid' => $payload['user_id'], ':date' => $date]
    )->fetch();

    $profile = fp_query(
        'SELECT weight, height, age, gender, objective, activity_level, target_weight, target_time_weeks
         FROM profiles WHERE user_id = :uid',
        [':uid' => $payload['user_id']]
    )->fetch();

    $targets = $profile
        ? calculate_macros(
            $profile['weight'], 
            $profile['height'],
            $profile['age'], 
            $profile['gender'],
            $profile['objective'], 
            $profile['activity_level'],
            $profile['target_weight'] ?? 0,
            $profile['target_time_weeks'] ?? 0
          )
        : null;

    fp_success([
        'date'    => $date,
        'totals'  => $sums,
        'targets' => $targets,
    ]);
}

/* ══════════════════════════════════════════════════════════════════════
   TENDENCIA SEMANAL
   ══════════════════════════════════════════════════════════════════════ */
function handle_weekly_trend(array $payload): never
{
    $rows = fp_query(
        'SELECT fe.log_date, ROUND(SUM(fe.calories)::numeric, 0) AS total_calories
         FROM food_entries fe
         JOIN profiles p ON p.profile_id = fe.profile_id
         WHERE p.user_id = :uid
           AND fe.log_date >= CURRENT_DATE - INTERVAL \'7 days\'
         GROUP BY fe.log_date
         ORDER BY fe.log_date ASC',
        [':uid' => $payload['user_id']]
    )->fetchAll();

    fp_success(['weekly_trend' => $rows]);
}

/* ══════════════════════════════════════════════════════════════════════
   EDITAR INGESTA (Modificar datos de una comida ya registrada)
   ══════════════════════════════════════════════════════════════════════ */
function handle_edit_entry(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
        fp_error(405, 'Método no permitido.');
    }

    $body    = fp_json_body();
    $entryId = fp_sanitize($body['entry_id'] ?? 0, 0, 'int');
    
    if ($entryId <= 0) fp_error(400, 'ID de entrada inválido.');

    $foodName  = fp_sanitize($body['food_name']     ?? '', 200);
    $portion   = fp_sanitize($body['portion_grams']    ?? 0, 0, 'float');
    $calories  = fp_sanitize($body['calories']         ?? 0, 0, 'float');
    $protein   = fp_sanitize($body['protein']          ?? 0, 0, 'float');
    $carbs     = fp_sanitize($body['carbs']            ?? 0, 0, 'float');
    $fat       = fp_sanitize($body['fat']              ?? 0, 0, 'float');

    // Metadatos de unidades
    $pAmount   = isset($body['portion_amount']) ? fp_sanitize($body['portion_amount'], 0, 'float') : null;
    $pUnit     = isset($body['portion_unit'])   ? fp_sanitize($body['portion_unit'], 50) : null;
    $uSize     = isset($body['unit_size'])     ? fp_sanitize($body['unit_size'], 20, 'slug') : null;

    /* Validar propiedad */
    $owned = fp_query(
        'SELECT 1 FROM food_entries fe
         JOIN profiles p ON p.profile_id = fe.profile_id
         WHERE fe.entry_id = :eid AND p.user_id = :uid',
        [':eid' => $entryId, ':uid' => $payload['user_id']]
    )->fetchColumn();

    if (!$owned) {
        fp_error(403, 'No tienes permiso para editar esta ingesta.');
    }

    fp_query(
        'UPDATE food_entries 
         SET food_name = :fn, portion_grams = :pg, calories = :cal, 
             protein = :pro, carbs = :car, fat = :fat,
             portion_amount = :pa, portion_unit = :pu, unit_size = :us
         WHERE entry_id = :eid',
        [
            ':fn'  => $foodName,
            ':pg'  => $portion,
            ':cal' => $calories,
            ':pro' => $protein,
            ':car' => $carbs,
            ':fat' => $fat,
            ':pa'  => $pAmount,
            ':pu'  => $pUnit,
            ':us'  => $uSize,
            ':eid' => $entryId,
        ]
    );

    fp_success(['message' => 'Ingesta actualizada correctamente.']);
}

/* ══════════════════════════════════════════════════════════════════════
   ELIMINAR INGESTA
   ══════════════════════════════════════════════════════════════════════ */
function handle_delete_entry(array $payload): never
{
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'DELETE' && $method !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }

    $body    = fp_json_body();
    // Priorizamos el parámetro de la URL (GET) pero aceptamos el cuerpo (POST/DELETE)
    $entryId = (int)($_GET['entry_id'] ?? $body['entry_id'] ?? 0);

    if ($entryId <= 0) {
        fp_error(400, "ID de entrada inválido: $entryId");
    }

    /* Verificar que la ingesta pertenece al usuario autenticado de forma directa */
    $owned = fp_query(
        'SELECT 1 FROM food_entries 
         WHERE entry_id = :eid AND profile_id = (SELECT profile_id FROM profiles WHERE user_id = :uid)',
        [':eid' => $entryId, ':uid' => $payload['user_id']]
    )->fetchColumn();

    if (!$owned) {
        fp_error(404, "No se encontró el registro #$entryId para tu usuario.");
    }

    fp_query('DELETE FROM food_entries WHERE entry_id = :eid', [':eid' => $entryId]);
    fp_success(['message' => 'Ingesta eliminada correctamente.']);
}
