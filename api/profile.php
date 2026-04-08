<?php
/**
 * FitPaisa — Endpoint de Gestión de Perfiles
 *
 * CRUD del perfil físico del usuario autenticado.
 * Incluye el cálculo de macronutrientes objetivo según la fórmula
 * de Mifflin-St Jeor documentada en el LLD §4.3.3.
 *
 * Rutas:
 *   GET  /api/profile.php?action=get      → Perfil + macros objetivo
 *   PUT  /api/profile.php?action=update   → Actualizar perfil físico
 *   POST /api/profile.php?action=log_body → Registrar medidas del día
 *   GET  /api/profile.php?action=history  → Historial de peso (últimos N días)
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_jwt.php';

fp_cors();

$payload = jwt_require(); /* 401 si no autenticado */
$action  = fp_sanitize($_GET['action'] ?? 'get', 32);

match ($action) {
    'get'       => handle_get_profile($payload),
    'update'       => handle_update_profile($payload),
    'setup_macros' => handle_setup_macros($payload),
    'log_body'     => handle_log_body($payload),
    'history'      => handle_body_history($payload),
    default     => fp_error(400, "Acción '{$action}' no reconocida."),
};

/* ══════════════════════════════════════════════════════════════════════
   GET PROFILE
   ══════════════════════════════════════════════════════════════════════ */
function handle_get_profile(array $payload): never
{
    $profile = fp_query(
        'SELECT p.*, u.full_name, u.email, u.phone
         FROM profiles p
         JOIN users u ON u.user_id = p.user_id
         WHERE p.user_id = :uid',
        [':uid' => $payload['user_id']]
    )->fetch();

    if (!$profile) {
        fp_error(404, 'Perfil no encontrado. Completa tu registro.');
    }

    $macros = calculate_macros(
        (float) $profile['weight'],
        (float) $profile['height'],
        (int)   $profile['age'],
        $profile['gender'],
        $profile['objective'],
        $profile['activity_level']
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

    $errors = [];
    if ($weight <= 0 || $weight > 500) $errors[] = 'Peso inválido.';
    if ($height <= 0 || $height > 300) $errors[] = 'Altura inválida.';
    if ($age    <= 0 || $age    > 120) $errors[] = 'Edad inválida.';
    if (!in_array($gender, ['MALE', 'FEMALE', 'OTHER'], true))
        $errors[] = 'Sexo inválido.';
    if (!in_array($objective, ['LOSE_WEIGHT', 'GAIN_MUSCLE', 'MAINTAIN', 'IMPROVE_HEALTH'], true))
        $errors[] = 'Objetivo inválido.';
    if (!in_array($activity, ['SEDENTARY', 'LIGHT', 'MODERATE', 'ACTIVE', 'VERY_ACTIVE'], true))
        $errors[] = 'Nivel de actividad inválido.';

    if (!empty($errors)) {
        fp_error(400, implode(' | ', $errors));
    }

    fp_query(
        'UPDATE profiles
         SET weight = :w, height = :h, age = :a, gender = :g,
             objective = :o, activity_level = :al, updated_at = NOW()
         WHERE user_id = :uid',
        [
            ':w'   => $weight,
            ':h'   => $height,
            ':a'   => $age,
            ':g'   => $gender,
            ':o'   => $objective,
            ':al'  => $activity,
            ':uid' => $payload['user_id'],
        ]
    );

    $macros = calculate_macros($weight, $height, $age, $gender, $objective, $activity);
    fp_success(['message' => 'Perfil actualizado correctamente.', 'macro_targets' => $macros]);
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
    $objective = fp_sanitize($body['objective'] ?? 'MAINTAIN', 30);

    if ($weight <= 0 || $weight > 500) fp_error(400, 'Peso inválido.');
    if ($height <= 0 || $height > 300) fp_error(400, 'Altura inválida.');
    if (!in_array($objective, ['LOSE_WEIGHT', 'GAIN_MUSCLE', 'MAINTAIN', 'IMPROVE_HEALTH'], true)) {
        fp_error(400, 'Objetivo inválido.');
    }

    /* Obtener el resto de datos que no piden en el setup (edad, género, actividad) de la BD */
    $current = fp_query(
        'SELECT age, gender, activity_level FROM profiles WHERE user_id = :uid',
        [':uid' => $payload['user_id']]
    )->fetch();

    $age = $current['age'] > 0 ? (int)$current['age'] : 25;
    $gender = $current['gender'] ?: 'OTHER';
    $activity = $current['activity_level'] ?: 'MODERATE';

    fp_query(
        'UPDATE profiles
         SET weight = :w, height = :h, objective = :o, updated_at = NOW()
         WHERE user_id = :uid',
        [
            ':w'   => $weight,
            ':h'   => $height,
            ':o'   => $objective,
            ':uid' => $payload['user_id'],
        ]
    );

    $macros = calculate_macros($weight, $height, $age, $gender, $objective, $activity);
    
    fp_success([
        'message' => 'Macros configurados correctamente.',
        'profile' => [
            'weight' => $weight,
            'height' => $height,
            'objective' => $objective
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

/* ══════════════════════════════════════════════════════════════════════
   ALGORITMO DE MACRONUTRIENTES — Mifflin-St Jeor (LLD §4.3.3)
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Calcula los objetivos diarios de macronutrientes según Mifflin-St Jeor.
 *
 * Paso 1: TMB = (10 × peso_kg) + (6.25 × talla_cm) − (5 × edad) ± constante_género
 * Paso 2: GET = TMB × factor_actividad
 * Paso 3: Ajuste por objetivo (±300 kcal)
 * Paso 4: Distribución: Proteína 30% | Carbos 45% | Grasa 25%
 *
 * @param float  $weight   Peso en kg.
 * @param float  $height   Altura en cm.
 * @param int    $age      Edad en años.
 * @param string $gender   'MALE' | 'FEMALE' | 'OTHER'.
 * @param string $objective 'LOSE_WEIGHT' | 'GAIN_MUSCLE' | 'MAINTAIN' | 'IMPROVE_HEALTH'
 * @param string $activity  Nivel de actividad física.
 * @return array            {calories, protein_g, carbs_g, fat_g, tmb, get}
 */
function calculate_macros(
    float  $weight,
    float  $height,
    int    $age,
    string $gender,
    string $objective,
    string $activity
): array {
    /* Paso 1: TMB */
    $genderConst = ($gender === 'FEMALE') ? -161 : 5;
    $tmb = (10 * $weight) + (6.25 * $height) - (5 * $age) + $genderConst;

    /* Paso 2: Factor de actividad */
    $activityFactors = [
        'SEDENTARY'   => 1.2,
        'LIGHT'       => 1.375,
        'MODERATE'    => 1.55,
        'ACTIVE'      => 1.725,
        'VERY_ACTIVE' => 1.9,
    ];
    $factor = $activityFactors[$activity] ?? 1.55;
    $get    = $tmb * $factor;

    /* Paso 3: Ajuste por objetivo */
    $adjustment = match ($objective) {
        'LOSE_WEIGHT'    => -300,
        'GAIN_MUSCLE'    => +300,
        default          => 0,      /* MAINTAIN, IMPROVE_HEALTH */
    };
    $targetCal = max(1200, $get + $adjustment); /* Mínimo 1200 kcal */

    /* Paso 4: Distribución de macros
       Proteína 4 kcal/g | Carbos 4 kcal/g | Grasa 9 kcal/g */
    return [
        'calories'  => round($targetCal),
        'protein_g' => round(($targetCal * 0.30) / 4),
        'carbs_g'   => round(($targetCal * 0.45) / 4),
        'fat_g'     => round(($targetCal * 0.25) / 9),
        'tmb'       => round($tmb),
        'get'       => round($get),
    ];
}
