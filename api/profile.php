<?php
/**
 * FitPaisa — Endpoint de Gestión de Perfiles
 *
 * CRUD del perfil físico del usuario autenticado.
 * Algoritmo de macros estilo Fitia: Déficit/Superávit exacto por peso objetivo.
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_jwt.php';

fp_cors();

/* Solo ejecutar el enrutador si este archivo es el punto de entrada directo */
if (strpos($_SERVER['SCRIPT_NAME'], 'profile.php') !== false) {
    $payload = jwt_require(); /* 401 si no autenticado */
    $action = fp_sanitize($_GET['action'] ?? 'get', 32);
    match ($action) {
        'get'          => handle_get_profile($payload),
        'update', 'save' => handle_update_profile($payload),
        'setup_macros' => handle_setup_macros($payload),
        'log_body'     => handle_log_body($payload),
        'history'      => handle_body_history($payload),
        default        => fp_error(400, "Acción '{$action}' no reconocida."),
    };
}

/* ══════════════════════════════════════════════════════════════════════
   GET PROFILE
   ══════════════════════════════════════════════════════════════════════ */
function handle_get_profile(array $payload): never
{
    $profile = fp_query(
        'SELECT p.*,
                u.full_name, u.email, u.phone,
                s.plan_type AS subscription_plan,
                s.status    AS subscription_status,
                s.end_date  AS subscription_end_date
         FROM profiles p
         JOIN users u ON u.user_id = p.user_id
         LEFT JOIN subscriptions s
           ON s.user_id = p.user_id AND s.status = \'ACTIVE\'
         WHERE p.user_id = :uid
         ORDER BY s.created_at DESC
         LIMIT 1',
        [':uid' => $payload['user_id']]
    )->fetch();

    if (!$profile) {
        fp_error(404, 'Perfil no encontrado. Completa tu registro.');
    }

    $macros = calculate_macros(
        (float) $profile['weight'],
        (float) $profile['height'],
        (int)   $profile['age'],
        $profile['gender'] ?: 'OTHER',
        $profile['objective'] ?: 'MAINTAIN',
        $profile['activity_level'] ?: 'MODERATE',
        (float) ($profile['target_weight'] ?? 0),
        (int) ($profile['target_time_weeks'] ?? 0)
    );

    fp_success(['profile' => $profile, 'macro_targets' => $macros]);
}

/* ══════════════════════════════════════════════════════════════════════
   UPDATE PROFILE
   ══════════════════════════════════════════════════════════════════════ */
function handle_update_profile(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }

    $body   = fp_json_body();
    $weight = (float) ($body['weight'] ?? 0);
    $height = (float) ($body['height'] ?? 0);
    $age    = (int)   ($body['age']    ?? 0);

    $gender    = fp_sanitize($body['gender']         ?? '', 10);
    $objective = fp_sanitize($body['objective']      ?? '', 30);
    $activity  = fp_sanitize($body['activity_level'] ?? '', 20);
    $targetWeight = (float) ($body['target_weight'] ?? $weight);
    $weeks = (int) ($body['target_time_weeks'] ?? 0);
    $timezone = fp_sanitize($body['timezone'] ?? null, 50);

    $errors = [];
    if ($weight <= 0 || $weight > 500) $errors[] = 'Peso inválido.';
    if ($height <= 0 || $height > 300) $errors[] = 'Altura inválida.';
    if ($age    <= 0 || $age    > 120) $errors[] = 'Edad inválida.';
    
    if (!empty($errors)) {
        fp_error(400, implode(' | ', $errors));
    }

    fp_query(
        'UPDATE profiles
         SET weight = :w, height = :h, age = :a, gender = :g,
             objective = :o, activity_level = :al, 
             target_weight = :tw, target_time_weeks = :ttw,
             timezone = COALESCE(:tz, timezone),
             updated_at = NOW()
         WHERE user_id = :uid',
        [
            ':w'   => $weight,
            ':h'   => $height,
            ':a'   => $age,
            ':g'   => $gender,
            ':o'   => $objective,
            ':al'  => $activity,
            ':tw'  => $targetWeight,
            ':ttw' => $weeks,
            ':tz'  => $timezone,
            ':uid' => $payload['user_id'],
        ]
    );

    $macros = calculate_macros($weight, $height, $age, $gender, $objective, $activity, $targetWeight, $weeks);
    
    /* Retornar el perfil actualizado para que el frontend se refresque */
    $profile = fp_query('SELECT * FROM profiles WHERE user_id = :uid', [':uid' => $payload['user_id']])->fetch();
    
    fp_success([
        'message' => 'Perfil actualizado correctamente.', 
        'macro_targets' => $macros,
        'profile' => $profile
    ]);
}

/* ══════════════════════════════════════════════════════════════════════
   SETUP MACROS (Primer inicio post-registro)
   ══════════════════════════════════════════════════════════════════════ */
function handle_setup_macros(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
        fp_error(405, 'Método no permitido.');
    }

    $body   = fp_json_body();
    $weight = (float) ($body['weight'] ?? 0);
    $height = (float) ($body['height'] ?? 0);
    $age    = (int)   ($body['age'] ?? 0);
    $objective = fp_sanitize($body['objective'] ?? 'MAINTAIN', 30);
    $targetWeight = (float) ($body['target_weight'] ?? $weight);
    $weeks = (int) ($body['target_time_weeks'] ?? 0);

    if ($weight <= 0 || $weight > 500) fp_error(400, 'Peso inválido.');
    if ($height <= 0 || $height > 300) fp_error(400, 'Altura inválida.');
    if ($age <= 0 || $age > 120) fp_error(400, 'Edad inválida.');

    /* Obtener género y actividad actuales */
    $current = fp_query(
        'SELECT gender, activity_level FROM profiles WHERE user_id = :uid',
        [':uid' => $payload['user_id']]
    )->fetch();

    $gender   = ($current && isset($current['gender']))         ? $current['gender']         : 'OTHER';
    $activity = ($current && isset($current['activity_level'])) ? $current['activity_level'] : 'MODERATE';

    /* FASE 1: Guardar datos básicos (Columnas que sabemos que existen) */
    try {
        fp_query(
            'INSERT INTO profiles (user_id, weight, height, age, gender, objective, activity_level, updated_at)
             VALUES (:uid, :w, :h, :a, :g, :o, :al, NOW())
             ON CONFLICT (user_id) DO UPDATE SET
                weight = EXCLUDED.weight,
                height = EXCLUDED.height,
                age = EXCLUDED.age,
                objective = EXCLUDED.objective,
                activity_level = EXCLUDED.activity_level,
                updated_at = NOW()',
            [
                ':uid' => $payload['user_id'],
                ':w'   => $weight,
                ':h'   => $height,
                ':a'   => $age,
                ':g'   => $gender,
                ':o'   => $objective,
                ':al'  => $activity
            ]
        );

        /* FASE 2: Intentar guardar datos de objetivo (Columnas nuevas) - FALLO SILENCIOSO */
        try {
            $db = fp_db();
            $st = $db->prepare('UPDATE profiles SET target_weight = :tw, target_time_weeks = :ttw WHERE user_id = :uid');
            $st->execute([
                ':tw'  => $targetWeight,
                ':ttw' => $weeks,
                ':uid' => $payload['user_id']
            ]);
        } catch (Exception $e) {
            // Si las columnas no existen, simplemente ignorar y continuar el proceso
            error_log("[FitPaisa][MIGRATION_PENDING] " . $e->getMessage());
        }

    } catch (Exception $e) {
        fp_error(500, 'Error crítico al guardar el perfil básico.');
    }

    $macros = calculate_macros($weight, $height, $age, $gender, $objective, $activity, $targetWeight, $weeks);
    
    fp_success([
        'message' => 'Macros configurados correctamente.',
        'profile' => [
            'weight' => $weight,
            'height' => $height,
            'age' => $age,
            'objective' => $objective,
            'target_weight' => $targetWeight,
            'target_time_weeks' => $weeks
        ],
        'macro_targets' => $macros
    ]);
}

/* ══════════════════════════════════════════════════════════════════════
   LOG BODY MEASUREMENTS
   ══════════════════════════════════════════════════════════════════════ */
function handle_log_body(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }

    $body   = fp_json_body();
    $weight = (float) ($body['weight'] ?? 0);

    if ($weight <= 0) {
        fp_error(400, 'El peso es obligatorio y debe ser mayor a 0.');
    }

    $profile = fp_query(
        'SELECT profile_id FROM profiles WHERE user_id = :uid',
        [':uid' => $payload['user_id']]
    )->fetchColumn();

    if (!$profile) {
        fp_error(404, 'Perfil no encontrado.');
    }

    fp_query(
        'INSERT INTO body_logs (profile_id, weight, waist, hips, chest, log_date)
         VALUES (:pid, :w, :waist, :hips, :chest, CURRENT_DATE)',
        [
            ':pid'   => $profile,
            ':w'     => $weight,
            ':waist' => ($body['waist'] ?? null) ?: null,
            ':hips'  => ($body['hips']  ?? null) ?: null,
            ':chest' => ($body['chest'] ?? null) ?: null,
        ]
    );

    /* Actualizar peso actual en perfil */
    fp_query(
        'UPDATE profiles SET weight = :w, updated_at = NOW() WHERE profile_id = :pid',
        [':w' => $weight, ':pid' => $profile]
    );

    fp_success(['message' => 'Medidas registradas correctamente.'], 201);
}

/* ══════════════════════════════════════════════════════════════════════
   HISTORIAL CORPORAL
   ══════════════════════════════════════════════════════════════════════ */
function handle_body_history(array $payload): never
{
    $days = min((int) ($_GET['days'] ?? 30), 365);

    $rows = fp_query(
        'SELECT bl.log_date, bl.weight, bl.waist, bl.hips, bl.chest
         FROM body_logs bl
         JOIN profiles p ON p.profile_id = bl.profile_id
         WHERE p.user_id = :uid
           AND bl.log_date >= CURRENT_DATE - :days * INTERVAL \'1 day\'
         ORDER BY bl.log_date ASC',
        [':uid' => $payload['user_id'], ':days' => $days]
    )->fetchAll();

    fp_success(['history' => $rows, 'days' => $days]);
}

/**
 * Calcula los objetivos diarios de macronutrientes según el método de Fitia.
 */
function calculate_macros(
    $weight,
    $height,
    $age,
    $gender,
    $objective,
    $activity,
    $targetWeight = 0.0,
    $weeks = 0
): array {
    $weight = (float)($weight ?: 0);
    $height = (float)($height ?: 0);
    $age    = (int)($age ?: 25);
    $gender = $gender ?: 'OTHER';
    $activity = $activity ?: 'MODERATE';
    $objective = $objective ?: 'MAINTAIN';

    /* 1. TMB - Mifflin-St Jeor */
    /* Hombres: 10 * peso + 6.25 * altura - 5 * edad + 5 */
    /* Mujeres: 10 * peso + 6.25 * altura - 5 * edad - 161 */
    $genderConst = ($gender === 'FEMALE') ? -161 : 5;
    $tmb = (10 * $weight) + (6.25 * $height) - (5 * $age) + $genderConst;

    /* 2. Factor de actividad (GET/TDEE) */
    $activityFactors = [
        'SEDENTARY'   => 1.2,
        'LIGHT'       => 1.375,
        'MODERATE'    => 1.55,
        'ACTIVE'      => 1.725,
        'VERY_ACTIVE' => 1.9,
    ];
    $factor = $activityFactors[$activity] ?? 1.55;
    $tdee   = $tmb * $factor;

    /* 3. Ajuste por Objetivo */
    /* Perder grasa: TDEE - 20% | Mantener: TDEE | Ganar músculo: TDEE + 10% */
    $adjustmentPercent = match($objective) {
        'LOSE_WEIGHT' => -0.20,
        'GAIN_MUSCLE' => 0.10,
        default       => 0.00
    };
    
    $targetCal = $tdee * (1 + $adjustmentPercent);
    
    // Protección absoluta: Mínimo 1200 kcal
    $targetCal = max(1200, $targetCal);

    /* 4. Reparto de Macros (Configuración Pro de Fitia)
       - Proteína: 2.0g por cada kg de peso corporal (g * 4 kcal)
       - Grasas: 25% del total de las calorías del objetivo (cal / 9)
       - Carbohidratos: El resto de las calorías restantes (cal / 4)
    */
    
    $protGrams = $weight * 2.0;
    $fatGrams  = ($targetCal * 0.25) / 9;
    
    // Calorías consumidas por prot y grasas
    $consumedCal = ($protGrams * 4) + ($fatGrams * 9);
    
    // Resto a carbohidratos
    $carbCal = max(0, $targetCal - $consumedCal);
    $carbGrams = $carbCal / 4;

    return [
        'calories'   => (int)round($targetCal),
        'protein_g'  => (int)round($protGrams),
        'carbs_g'    => (int)round($carbGrams),
        'fat_g'      => (int)round($fatGrams),
        'tmb'        => (int)round($tmb),
        'tdee'       => (int)round($tdee),
        'adjustment' => (int)round($targetCal - $tdee)
    ];
}
