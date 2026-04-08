<?php
/**
 * FitPaisa — Endpoint de Entrenamientos
 *
 * Gestión completa de planes de entrenamiento y ejercicios.
 * Los coaches pueden crear/aprobar planes; los usuarios los consultan.
 *
 * Rutas:
 *   GET  /api/workouts.php?action=my_plans      → Planes del usuario
 *   GET  /api/workouts.php?action=plan&id=N     → Plan específico + ejercicios
 *   POST /api/workouts.php?action=create_plan   → Crear nuevo plan (COACH/ADMIN)
 *   POST /api/workouts.php?action=add_exercise  → Añadir ejercicio a plan
 *   PUT  /api/workouts.php?action=approve       → Aprobar plan (COACH)
 *   GET  /api/workouts.php?action=records       → Récords personales por ejercicio
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_jwt.php';

fp_cors();

$payload = jwt_require();
$action  = fp_sanitize($_GET['action'] ?? 'my_plans', 32);

match ($action) {
    'my_plans'     => handle_my_plans($payload),
    'plan'         => handle_get_plan($payload),
    'create_plan'  => handle_create_plan($payload),
    'add_exercise' => handle_add_exercise($payload),
    'approve'      => handle_approve_plan($payload),
    'records'      => handle_personal_records($payload),
    default        => fp_error(400, "Acción '{$action}' no reconocida."),
};

/* ══════════════════════════════════════════════════════════════════════
   MIS PLANES
   ══════════════════════════════════════════════════════════════════════ */
function handle_my_plans(array $payload): never
{
    $plans = fp_query(
        'SELECT wp.plan_id, wp.name, wp.status, wp.start_date, wp.end_date, wp.created_at,
                u.full_name AS coach_name
         FROM workout_plans wp
         LEFT JOIN users u ON u.user_id = wp.coach_id
         WHERE wp.user_id = :uid
         ORDER BY wp.created_at DESC',
        [':uid' => $payload['user_id']]
    )->fetchAll();

    fp_success(['plans' => $plans]);
}

/* ══════════════════════════════════════════════════════════════════════
   PLAN ESPECÍFICO CON EJERCICIOS
   ══════════════════════════════════════════════════════════════════════ */
function handle_get_plan(array $payload): never
{
    $planId = (int) ($_GET['id'] ?? 0);
    if ($planId <= 0) {
        fp_error(400, 'ID de plan inválido.');
    }

    $plan = fp_query(
        'SELECT wp.*, u.full_name AS coach_name
         FROM workout_plans wp
         LEFT JOIN users u ON u.user_id = wp.coach_id
         WHERE wp.plan_id = :pid AND wp.user_id = :uid',
        [':pid' => $planId, ':uid' => $payload['user_id']]
    )->fetch();

    /* Los coaches también pueden ver planes que ellos crearon */
    if (!$plan && in_array($payload['role'], ['COACH', 'ADMIN'], true)) {
        $plan = fp_query(
            'SELECT wp.*, u.full_name AS coach_name
             FROM workout_plans wp
             LEFT JOIN users u ON u.user_id = wp.coach_id
             WHERE wp.plan_id = :pid AND wp.coach_id = :uid',
            [':pid' => $planId, ':uid' => $payload['user_id']]
        )->fetch();
    }

    if (!$plan) {
        fp_error(404, 'Plan no encontrado o sin acceso.');
    }

    $exercises = fp_query(
        'SELECT * FROM exercises WHERE plan_id = :pid ORDER BY day_of_week, exercise_id',
        [':pid' => $planId]
    )->fetchAll();

    fp_success(['plan' => $plan, 'exercises' => $exercises]);
}

/* ══════════════════════════════════════════════════════════════════════
   CREAR PLAN (COACH / ADMIN)
   ══════════════════════════════════════════════════════════════════════ */
function handle_create_plan(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }
    if (!in_array($payload['role'], ['COACH', 'ADMIN'], true)) {
        fp_error(403, 'Solo los entrenadores pueden crear planes.');
    }

    $body      = fp_json_body();
    $userId    = (int) ($body['user_id']    ?? 0);
    $name      = fp_sanitize($body['name']  ?? '', 200);
    $startDate = fp_sanitize($body['start_date'] ?? date('Y-m-d'), 10);
    $endDate   = fp_sanitize($body['end_date']   ?? '', 10) ?: null;

    $errors = [];
    if ($userId <= 0)   $errors[] = 'ID de usuario inválido.';
    if (empty($name))   $errors[] = 'El nombre del plan es obligatorio.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $errors[] = 'Fecha de inicio inválida.';

    if (!empty($errors)) {
        fp_error(400, implode(' | ', $errors));
    }

    /* Verificar que el usuario existe */
    $userExists = fp_query(
        'SELECT 1 FROM users WHERE user_id = :uid AND is_active = TRUE',
        [':uid' => $userId]
    )->fetchColumn();

    if (!$userExists) {
        fp_error(404, 'Cliente no encontrado.');
    }

    $stmt = fp_query(
        "INSERT INTO workout_plans (coach_id, user_id, name, status, start_date, end_date)
         VALUES (:cid, :uid, :name, 'DRAFT', :sd, :ed)
         RETURNING plan_id",
        [
            ':cid'  => $payload['user_id'],
            ':uid'  => $userId,
            ':name' => $name,
            ':sd'   => $startDate,
            ':ed'   => $endDate,
        ]
    );

    $planId = $stmt->fetchColumn();
    fp_success(['message' => 'Plan creado en estado BORRADOR.', 'plan_id' => $planId], 201);
}

/* ══════════════════════════════════════════════════════════════════════
   AÑADIR EJERCICIO
   ══════════════════════════════════════════════════════════════════════ */
function handle_add_exercise(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }

    $body       = fp_json_body();
    $planId     = (int) ($body['plan_id']      ?? 0);
    $name       = fp_sanitize($body['name']    ?? '', 200);
    $sets       = (int) ($body['sets']         ?? 0);
    $reps       = (int) ($body['reps']         ?? 0);
    $loadKg     = isset($body['load_kg'])  ? (float) $body['load_kg']  : null;
    $restSecs   = (int) ($body['rest_seconds'] ?? 60);
    $day        = fp_sanitize($body['day_of_week'] ?? '', 5);
    $notes      = fp_sanitize($body['notes']   ?? '', 1000);

    $validDays = ['MON','TUE','WED','THU','FRI','SAT','SUN'];
    $errors = [];
    if ($planId <= 0) $errors[] = 'ID de plan inválido.';
    if (empty($name)) $errors[] = 'El nombre del ejercicio es obligatorio.';
    if ($sets  <= 0)  $errors[] = 'El número de series debe ser mayor a 0.';
    if ($reps  <= 0)  $errors[] = 'El número de repeticiones debe ser mayor a 0.';
    if (!in_array($day, $validDays, true)) $errors[] = 'Día de la semana inválido.';

    if (!empty($errors)) {
        fp_error(400, implode(' | ', $errors));
    }

    /* Solo el coach del plan o un admin puede añadir ejercicios */
    $plan = fp_query(
        'SELECT coach_id, status FROM workout_plans WHERE plan_id = :pid',
        [':pid' => $planId]
    )->fetch();

    if (!$plan) {
        fp_error(404, 'Plan no encontrado.');
    }
    if ($plan['status'] === 'ARCHIVED') {
        fp_error(409, 'No se pueden modificar planes archivados.');
    }
    if ($plan['coach_id'] != $payload['user_id'] && $payload['role'] !== 'ADMIN') {
        fp_error(403, 'No tienes permisos para modificar este plan.');
    }

    $stmt = fp_query(
        'INSERT INTO exercises (plan_id, name, sets, reps, load_kg, rest_seconds, day_of_week, notes)
         VALUES (:pid, :name, :sets, :reps, :load, :rest, :day, :notes)
         RETURNING exercise_id',
        [
            ':pid'   => $planId,
            ':name'  => $name,
            ':sets'  => $sets,
            ':reps'  => $reps,
            ':load'  => $loadKg,
            ':rest'  => $restSecs,
            ':day'   => $day,
            ':notes' => $notes ?: null,
        ]
    );

    fp_success(['message' => 'Ejercicio añadido.', 'exercise_id' => $stmt->fetchColumn()], 201);
}

/* ══════════════════════════════════════════════════════════════════════
   APROBAR PLAN (COACH)
   ══════════════════════════════════════════════════════════════════════ */
function handle_approve_plan(array $payload): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }
    if (!in_array($payload['role'], ['COACH', 'ADMIN'], true)) {
        fp_error(403, 'Solo los entrenadores pueden aprobar planes.');
    }

    $body   = fp_json_body();
    $planId = (int) ($body['plan_id'] ?? 0);

    if ($planId <= 0) {
        fp_error(400, 'ID de plan inválido.');
    }

    $plan = fp_query(
        'SELECT coach_id, status FROM workout_plans WHERE plan_id = :pid',
        [':pid' => $planId]
    )->fetch();

    if (!$plan) {
        fp_error(404, 'Plan no encontrado.');
    }
    if (!in_array($plan['status'], ['DRAFT', 'PENDING_APPROVAL'], true)) {
        fp_error(409, "El plan no puede aprobarse desde el estado '{$plan['status']}'.");
    }

    fp_query(
        "UPDATE workout_plans SET status = 'ACTIVE' WHERE plan_id = :pid",
        [':pid' => $planId]
    );

    fp_success(['message' => 'Plan activado correctamente.']);
}

/* ══════════════════════════════════════════════════════════════════════
   RÉCORDS PERSONALES
   ══════════════════════════════════════════════════════════════════════ */
function handle_personal_records(array $payload): never
{
    $records = fp_query(
        'SELECT e.name AS exercise_name, MAX(e.load_kg) AS max_load_kg
         FROM exercises e
         JOIN workout_plans wp ON wp.plan_id = e.plan_id
         WHERE wp.user_id = :uid AND e.load_kg IS NOT NULL
         GROUP BY e.name
         ORDER BY max_load_kg DESC',
        [':uid' => $payload['user_id']]
    )->fetchAll();

    fp_success(['records' => $records]);
}
