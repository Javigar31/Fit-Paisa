<?php
/**
 * FitPaisa — Script de Inicialización de Base de Datos
 *
 * Crea todas las tablas en Neon PostgreSQL con sintaxis nativa de PostgreSQL.
 * El script es IDEMPOTENTE: puede ejecutarse múltiples veces sin destruir datos.
 *
 * ⚠️  PROTECCIÓN: Requiere token secreto en ?token= para ejecutarse.
 *     Configura SETUP_TOKEN en las variables de entorno de Vercel.
 *
 * Uso: GET /api/setup-db.php?token=TU_TOKEN_SECRETO
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 * @version  1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

header('Content-Type: application/json; charset=utf-8');

/* ── Protección por token ─────────────────────────────────────────────── */
$setupToken   = getenv('SETUP_TOKEN') ?: 'FITPAISA_SETUP_2026';
$providedToken = $_GET['token'] ?? '';

if (!hash_equals($setupToken, $providedToken)) {
    fp_error(403, 'Token inválido. Acceso denegado.');
}

$db = fp_db();
$results = [];
$errors  = [];

/**
 * Ejecuta un bloque SQL y registra el resultado.
 *
 * @param PDO    $pdo   Conexión activa.
 * @param string $name  Nombre descriptivo de la operación.
 * @param string $sql   SQL a ejecutar.
 */
function run_step(PDO $pdo, string $name, string $sql): void
{
    global $results, $errors;
    try {
        $pdo->exec($sql);
        $results[] = "✓ {$name}";
    } catch (PDOException $e) {
        $errors[] = "✗ {$name}: " . $e->getMessage();
        error_log("[FitPaisa][SETUP] Error en '{$name}': " . $e->getMessage());
    }
}

/* ══════════════════════════════════════════════════════════════════════
   TIPOS ENUM (PostgreSQL nativo)
   Se usan DO $$ para crearlos solo si no existen (idempotente)
   ══════════════════════════════════════════════════════════════════════ */

run_step($db, 'ENUM: user_role', "
    DO \$\$ BEGIN
        CREATE TYPE user_role AS ENUM ('USER', 'COACH', 'ADMIN');
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

run_step($db, 'ENUM: gender_type', "
    DO \$\$ BEGIN
        CREATE TYPE gender_type AS ENUM ('MALE', 'FEMALE', 'OTHER');
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

run_step($db, 'ENUM: objective_type', "
    DO \$\$ BEGIN
        CREATE TYPE objective_type AS ENUM ('LOSE_WEIGHT', 'GAIN_MUSCLE', 'MAINTAIN', 'IMPROVE_HEALTH');
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

run_step($db, 'ENUM: activity_level', "
    DO \$\$ BEGIN
        CREATE TYPE activity_level AS ENUM ('SEDENTARY', 'LIGHT', 'MODERATE', 'ACTIVE', 'VERY_ACTIVE');
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

run_step($db, 'ENUM: meal_type', "
    DO \$\$ BEGIN
        CREATE TYPE meal_type AS ENUM ('BREAKFAST', 'LUNCH', 'DINNER', 'SNACK');
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

run_step($db, 'ENUM: plan_status', "
    DO \$\$ BEGIN
        CREATE TYPE plan_status AS ENUM ('DRAFT', 'PENDING_APPROVAL', 'ACTIVE', 'ARCHIVED');
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

run_step($db, 'ENUM: day_of_week', "
    DO \$\$ BEGIN
        CREATE TYPE day_of_week AS ENUM ('MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN');
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

run_step($db, 'ENUM: subscription_plan', "
    DO \$\$ BEGIN
        CREATE TYPE subscription_plan AS ENUM ('FREE', 'PREMIUM_MONTHLY', 'PREMIUM_ANNUAL');
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

run_step($db, 'ENUM: subscription_status', "
    DO \$\$ BEGIN
        CREATE TYPE subscription_status AS ENUM ('ACTIVE', 'CANCELLED', 'EXPIRED', 'PENDING');
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

run_step($db, 'ENUM: notification_type', "
    DO \$\$ BEGIN
        CREATE TYPE notification_type AS ENUM (
            'WORKOUT_REMINDER', 'NUTRITION_ALERT',
            'PAYMENT_CONFIRM', 'PLAN_UPDATE', 'MESSAGE_RECEIVED'
        );
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

run_step($db, 'ENUM: notification_channel', "
    DO \$\$ BEGIN
        CREATE TYPE notification_channel AS ENUM ('EMAIL', 'IN_APP');
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

run_step($db, 'ENUM: notification_status', "
    DO \$\$ BEGIN
        CREATE TYPE notification_status AS ENUM ('PENDING', 'SENT', 'FAILED');
    EXCEPTION WHEN duplicate_object THEN NULL;
    END \$\$;
");

/* ══════════════════════════════════════════════════════════════════════
   TABLA: users — Tabla central del sistema
   ══════════════════════════════════════════════════════════════════════ */
run_step($db, 'TABLE: users', "
    CREATE TABLE IF NOT EXISTS users (
        user_id       SERIAL PRIMARY KEY,
        email         VARCHAR(150)    NOT NULL UNIQUE,
        password_hash VARCHAR(255)    NOT NULL,
        full_name     VARCHAR(200)    NOT NULL,
        phone         VARCHAR(30),
        role          user_role       NOT NULL DEFAULT 'USER',
        is_active     BOOLEAN         NOT NULL DEFAULT TRUE,
        login_attempts SMALLINT       NOT NULL DEFAULT 0,
        locked_until  TIMESTAMPTZ,
        last_login    TIMESTAMPTZ,
        created_at    TIMESTAMPTZ     NOT NULL DEFAULT NOW()
    );
");

run_step($db, 'INDEX: users_email', "
    CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(email);
");

/* ══════════════════════════════════════════════════════════════════════
   TABLA: profiles — Datos físicos del usuario (1:1 con users)
   ══════════════════════════════════════════════════════════════════════ */
run_step($db, 'TABLE: profiles', "
    CREATE TABLE IF NOT EXISTS profiles (
        profile_id     SERIAL PRIMARY KEY,
        user_id        INTEGER         NOT NULL UNIQUE REFERENCES users(user_id) ON DELETE CASCADE,
        weight         DECIMAL(5,2)    NOT NULL CHECK (weight > 0),
        height         DECIMAL(5,2)    NOT NULL CHECK (height > 0),
        age            SMALLINT        NOT NULL CHECK (age > 0 AND age < 130),
        gender         gender_type     NOT NULL,
        objective      objective_type  NOT NULL,
        activity_level activity_level  NOT NULL DEFAULT 'MODERATE',
        target_weight  DECIMAL(5,2),
        target_time_weeks SMALLINT,
        updated_at     TIMESTAMPTZ     NOT NULL DEFAULT NOW()
    );
");

// Safely alter existing profiles table
try {
    $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS target_weight DECIMAL(5,2)");
} catch (PDOException $e) { /* ignore if already exists or IF NOT EXISTS handled it */ }
try {
    $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS target_time_weeks SMALLINT");
} catch (PDOException $e) { /* ignore if already exists */ }

/* ══════════════════════════════════════════════════════════════════════
   TABLA: food_catalog — Catálogo local de alimentos
   ══════════════════════════════════════════════════════════════════════ */
run_step($db, 'TABLE: food_catalog', "
    CREATE TABLE IF NOT EXISTS food_catalog (
        food_id       SERIAL PRIMARY KEY,
        name          VARCHAR(255)    NOT NULL,
        calories_100g DECIMAL(6,2)    NOT NULL,
        protein_100g  DECIMAL(6,2)    NOT NULL,
        carbs_100g    DECIMAL(6,2)    NOT NULL,
        fat_100g      DECIMAL(6,2)    NOT NULL,
        unit_name     VARCHAR(50),
        weight_std    DECIMAL(5,2),
        weight_small  DECIMAL(5,2),
        weight_medium DECIMAL(5,2),
        weight_large  DECIMAL(5,2),
        is_liquid     BOOLEAN         DEFAULT FALSE
    );
");

// Seeder para food_catalog
$stmtCount = $db->query("SELECT COUNT(*) FROM food_catalog");
if ((int)$stmtCount->fetchColumn() === 0) {
        // Carnes y Aves
        ['Pechuga de pollo (cruda)', 120, 22.5, 0, 2.6, null, null, null, null, null, false],
        ['Alitas de pollo (con piel)', 215, 18, 0.5, 15, 'Ala', 35, null, null, null, false],
        ['Jamón cocido', 101, 18, 1, 3, 'Loncha', 20, null, null, null, false],

        // Huevos y Lácteos
        ['Huevo entero', 143, 12.6, 0.7, 9.5, 'Huevo', 50, 40, 50, 60, false],
        ['Yema de huevo', 322, 15.9, 3.6, 26.5, 'Yema', 17, null, null, null, false],
        ['Leche entera', 61, 3.2, 4.8, 3.3, 'Vaso', 250, null, null, null, true],
        ['Yogur natural', 61, 3.5, 4.7, 3.3, 'Unidad', 125, null, null, null, false],

        // Cereales y Pan
        ['Arroz blanco (crudo)', 360, 6.6, 79.3, 0.6, 'Taza', 180, null, null, null, false],
        ['Pan blanco', 265, 8.8, 49, 3.2, 'Rebanada', 30, null, null, null, false],
        ['Pan integral', 252, 12.4, 42.7, 3.5, 'Rebanada', 35, null, null, null, false],
        ['Galleta María', 440, 7, 75, 13, 'Galleta', 6, null, null, null, false],

        // Otros y Líquidos
        ['Aceite de oliva', 884, 0, 0, 100, 'Cucharada', 15, null, null, null, true],
        ['Vino tinto', 85, 0.1, 2.6, 0, 'Copa', 150, null, null, null, true],
    ];

    $stmt = $db->prepare("INSERT INTO food_catalog (name, calories_100g, protein_100g, carbs_100g, fat_100g, unit_name, weight_std, weight_small, weight_medium, weight_large, is_liquid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($foods as $f) {
        $stmt->execute($f);
    }
    $results[] = "✓ SEED: food_catalog (" . count($foods) . " ítems)";
}

/* ══════════════════════════════════════════════════════════════════════
   TABLA: food_entries — Registro diario de nutrición
   ══════════════════════════════════════════════════════════════════════ */
run_step($db, 'TABLE: food_entries', "
    CREATE TABLE IF NOT EXISTS food_entries (
        entry_id      SERIAL PRIMARY KEY,
        profile_id    INTEGER         NOT NULL REFERENCES profiles(profile_id) ON DELETE CASCADE,
        food_name     VARCHAR(200)    NOT NULL,
        portion_grams DECIMAL(6,2)    NOT NULL CHECK (portion_grams > 0),
        calories      DECIMAL(7,2)    NOT NULL CHECK (calories >= 0),
        protein       DECIMAL(6,2)    NOT NULL CHECK (protein >= 0),
        carbs         DECIMAL(6,2)    NOT NULL CHECK (carbs >= 0),
        fat           DECIMAL(6,2)    NOT NULL CHECK (fat >= 0),
        meal_type     meal_type       NOT NULL,
        log_date      DATE            NOT NULL DEFAULT CURRENT_DATE,
        portion_amount DECIMAL(6,2),
        portion_unit   VARCHAR(50),
        unit_size      VARCHAR(20),
        created_at    TIMESTAMPTZ     NOT NULL DEFAULT NOW()
    );
");


run_step($db, 'INDEX: food_entries_profile_date', "
    CREATE INDEX IF NOT EXISTS idx_food_profile_date
    ON food_entries(profile_id, log_date);
");

/* ══════════════════════════════════════════════════════════════════════
   TABLA: workout_plans — Planes de entrenamiento
   ══════════════════════════════════════════════════════════════════════ */
run_step($db, 'TABLE: workout_plans', "
    CREATE TABLE IF NOT EXISTS workout_plans (
        plan_id    SERIAL PRIMARY KEY,
        coach_id   INTEGER         REFERENCES users(user_id) ON DELETE SET NULL,
        user_id    INTEGER         NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        name       VARCHAR(200)    NOT NULL,
        status     plan_status     NOT NULL DEFAULT 'DRAFT',
        start_date DATE            NOT NULL DEFAULT CURRENT_DATE,
        end_date   DATE,
        created_at TIMESTAMPTZ     NOT NULL DEFAULT NOW()
    );
");

run_step($db, 'INDEX: workout_plans_user', "
    CREATE INDEX IF NOT EXISTS idx_plans_user ON workout_plans(user_id);
");

/* ══════════════════════════════════════════════════════════════════════
   TABLA: exercises — Ejercicios de un plan
   ══════════════════════════════════════════════════════════════════════ */
run_step($db, 'TABLE: exercises', "
    CREATE TABLE IF NOT EXISTS exercises (
        exercise_id  SERIAL PRIMARY KEY,
        plan_id      INTEGER         NOT NULL REFERENCES workout_plans(plan_id) ON DELETE CASCADE,
        name         VARCHAR(200)    NOT NULL,
        sets         SMALLINT        NOT NULL CHECK (sets > 0),
        reps         SMALLINT        NOT NULL CHECK (reps > 0),
        load_kg      DECIMAL(5,2)    CHECK (load_kg >= 0),
        rest_seconds SMALLINT        NOT NULL DEFAULT 60 CHECK (rest_seconds >= 0),
        day_of_week  day_of_week     NOT NULL,
        notes        TEXT,
        created_at   TIMESTAMPTZ     NOT NULL DEFAULT NOW()
    );
");

/* ══════════════════════════════════════════════════════════════════════
   TABLA: body_logs — Historial de medidas físicas
   ══════════════════════════════════════════════════════════════════════ */
run_step($db, 'TABLE: body_logs', "
    CREATE TABLE IF NOT EXISTS body_logs (
        log_id     SERIAL PRIMARY KEY,
        profile_id INTEGER         NOT NULL REFERENCES profiles(profile_id) ON DELETE CASCADE,
        weight     DECIMAL(5,2)    NOT NULL CHECK (weight > 0),
        waist      DECIMAL(5,2)    CHECK (waist > 0),
        hips       DECIMAL(5,2)    CHECK (hips > 0),
        chest      DECIMAL(5,2)    CHECK (chest > 0),
        log_date   DATE            NOT NULL DEFAULT CURRENT_DATE,
        created_at TIMESTAMPTZ     NOT NULL DEFAULT NOW()
    );
");

/* ══════════════════════════════════════════════════════════════════════
   TABLA: messages — Mensajería interna
   ══════════════════════════════════════════════════════════════════════ */
run_step($db, 'TABLE: messages', "
    CREATE TABLE IF NOT EXISTS messages (
        message_id  SERIAL PRIMARY KEY,
        sender_id   INTEGER         NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        receiver_id INTEGER         NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        content     VARCHAR(2000)   NOT NULL,
        sent_at     TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
        read_at     TIMESTAMPTZ,
        is_deleted  BOOLEAN         NOT NULL DEFAULT FALSE,
        CHECK (sender_id <> receiver_id)
    );
");

run_step($db, 'INDEX: messages_conversation', "
    CREATE INDEX IF NOT EXISTS idx_messages_conv
    ON messages(sender_id, receiver_id, sent_at DESC);
");

/* ══════════════════════════════════════════════════════════════════════
   TABLA: subscriptions — Suscripciones Premium
   ══════════════════════════════════════════════════════════════════════ */
run_step($db, 'TABLE: subscriptions', "
    CREATE TABLE IF NOT EXISTS subscriptions (
        subscription_id SERIAL PRIMARY KEY,
        user_id         INTEGER              NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        plan_type       subscription_plan    NOT NULL DEFAULT 'FREE',
        status          subscription_status  NOT NULL DEFAULT 'PENDING',
        external_ref    VARCHAR(200)         UNIQUE,
        start_date      DATE                 NOT NULL DEFAULT CURRENT_DATE,
        end_date        DATE                 NOT NULL,
        amount          DECIMAL(8,2)         NOT NULL CHECK (amount >= 0),
        created_at      TIMESTAMPTZ          NOT NULL DEFAULT NOW()
    );
");

/* ══════════════════════════════════════════════════════════════════════
   TABLA: progress_photos — Fotos de progreso
   ══════════════════════════════════════════════════════════════════════ */
run_step($db, 'TABLE: progress_photos', "
    CREATE TABLE IF NOT EXISTS progress_photos (
        photo_id    SERIAL PRIMARY KEY,
        profile_id  INTEGER         NOT NULL REFERENCES profiles(profile_id) ON DELETE CASCADE,
        file_url    VARCHAR(500)    NOT NULL,
        notes       TEXT,
        uploaded_at TIMESTAMPTZ     NOT NULL DEFAULT NOW()
    );
");

/* ══════════════════════════════════════════════════════════════════════
   TABLA: notifications — Notificaciones del sistema
   ══════════════════════════════════════════════════════════════════════ */
run_step($db, 'TABLE: notifications', "
    CREATE TABLE IF NOT EXISTS notifications (
        notification_id SERIAL PRIMARY KEY,
        user_id         INTEGER                NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
        type            notification_type      NOT NULL,
        channel         notification_channel   NOT NULL DEFAULT 'IN_APP',
        status          notification_status    NOT NULL DEFAULT 'PENDING',
        payload         JSONB,
        sent_at         TIMESTAMPTZ,
        created_at      TIMESTAMPTZ            NOT NULL DEFAULT NOW()
    );
");

/* ── Respuesta final ──────────────────────────────────────────────────── */
$status = empty($errors) ? 'ok' : 'partial';
http_response_code(empty($errors) ? 200 : 207);

echo json_encode([
    'success' => empty($errors),
    'status'  => $status,
    'results' => $results,
    'errors'  => $errors,
    'summary' => sprintf(
        '%d operaciones exitosas, %d errores.',
        count($results),
        count($errors)
    ),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
