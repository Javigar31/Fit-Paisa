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
        fat_100g      DECIMAL(6,2)    NOT NULL
    );
");

// Seeder para food_catalog
$stmtCount = $db->query("SELECT COUNT(*) FROM food_catalog");
if ((int)$stmtCount->fetchColumn() === 0) {
    $foods = [
        // Carnes y Aves (crudos)
        ['Pechuga de pollo', 120, 22.5, 0, 2.6],
        ['Muslo de pollo (sin piel)', 121, 19.3, 0, 4.8],
        ['Ala de pollo (con piel)', 191, 17.5, 0, 12.8],
        ['Pechuga de pavo', 104, 21.9, 0, 1.2],
        ['Carne de res magra', 133, 21.4, 0, 5.3],
        ['Carne de res (entrecot)', 242, 17.2, 0, 19],
        ['Carne de res molida (10% grasa)', 176, 20, 0, 10],
        ['Lomo de cerdo', 143, 21.1, 0, 5.5],
        ['Chuleta de cerdo', 196, 20.3, 0, 12.1],
        ['Tocino/Panceta', 541, 37, 1.4, 42],
        ['Jamón cocido', 101, 18, 1, 3],
        ['Jamón serrano', 242, 30, 0, 14],

        // Pescados y Mariscos (crudos)
        ['Salmón', 127, 20.5, 0, 4.4],
        ['Atún fresco', 108, 23.4, 0, 0.9],
        ['Atún en conserva (natural)', 86, 19.4, 0, 0.9],
        ['Atún en conserva (aceite)', 198, 29, 0, 8.2],
        ['Merluza', 72, 11.8, 0.1, 2.8],
        ['Bacalao', 82, 17.8, 0, 0.7],
        ['Trucha', 141, 19.9, 0, 6.2],
        ['Sardinas', 208, 24.6, 0, 11.5],
        ['Gambas / Langostinos', 85, 20.1, 0.2, 0.5],
        ['Mejillones', 86, 11.9, 3.7, 2.2],
        ['Pulpo', 82, 14.9, 2.2, 1],
        ['Calamares', 92, 15.6, 3.1, 1.4],

        // Huevos y Lácteos
        ['Huevo entero', 143, 12.6, 0.7, 9.5],
        ['Clara de huevo', 52, 10.9, 0.7, 0.2],
        ['Yema de huevo', 322, 15.9, 3.6, 26.5],
        ['Leche entera', 61, 3.2, 4.8, 3.3],
        ['Leche semidesnatada', 46, 3.3, 4.8, 1.6],
        ['Leche desnatada', 34, 3.4, 4.9, 0.1],
        ['Yogur natural', 61, 3.5, 4.7, 3.3],
        ['Yogur griego (0% grasa)', 59, 10, 3.6, 0.4],
        ['Queso fresco / Burgos', 174, 12.5, 3.5, 12],
        ['Queso cottage', 98, 11.1, 3.4, 4.3],
        ['Queso mozzarella', 280, 28, 3.1, 17],
        ['Queso parmesano', 431, 38, 4.1, 29],
        ['Queso cheddar', 403, 25, 1.3, 33],
        ['Mantequilla', 717, 0.9, 0.1, 81],

        // Cereales, Tubérculos y Legumbres
        ['Arroz blanco (crudo)', 360, 6.6, 79.3, 0.6],
        ['Arroz integral (crudo)', 370, 7.9, 77.2, 2.9],
        ['Pasta de trigo (cruda)', 350, 12, 71, 1.5],
        ['Avena en copos', 389, 16.9, 66.3, 6.9],
        ['Quinoa (cruda)', 368, 14.1, 64.2, 6.1],
        ['Pan blanco', 265, 8.8, 49, 3.2],
        ['Pan integral', 252, 12.4, 42.7, 3.5],
        ['Patata / Papa (cruda)', 77, 2, 17.5, 0.1],
        ['Boniato / Camote (crudo)', 86, 1.6, 20.1, 0.1],
        ['Lentejas (secas)', 353, 25.8, 60.1, 1.1],
        ['Garbanzos (secos)', 364, 19.3, 61, 6],
        ['Alubias / Frijoles (secos)', 341, 21.6, 62.4, 1.4],
        ['Guisantes / Arvejas', 81, 5.4, 14.5, 0.4],
        ['Maíz dulce', 86, 3.2, 19, 1.2],

        // Verduras y Hortalizas
        ['Brócoli', 34, 2.8, 6.6, 0.4],
        ['Espinacas', 23, 2.9, 3.6, 0.4],
        ['Zanahoria', 41, 0.9, 9.6, 0.2],
        ['Tomate', 18, 0.9, 3.9, 0.2],
        ['Lechuga', 15, 1.4, 2.9, 0.2],
        ['Cebolla', 40, 1.1, 9.3, 0.1],
        ['Pimiento rojo', 31, 1, 6, 0.3],
        ['Pimiento verde', 20, 0.9, 4.6, 0.2],
        ['Calabacín', 17, 1.2, 3.1, 0.3],
        ['Berenjena', 25, 1, 5.9, 0.2],
        ['Pepino', 15, 0.7, 3.6, 0.1],
        ['Espárragos', 20, 2.2, 3.9, 0.1],
        ['Champiñones', 22, 3.1, 3.3, 0.3],
        ['Aguacate / Palta', 160, 2, 8.5, 14.7],

        // Frutas
        ['Plátano / Banana', 89, 1.1, 22.8, 0.3],
        ['Manzana', 52, 0.3, 13.8, 0.2],
        ['Pera', 57, 0.4, 15.2, 0.1],
        ['Naranja', 47, 0.9, 11.8, 0.1],
        ['Kiwi', 61, 1.1, 14.7, 0.5],
        ['Piña', 50, 0.5, 13.1, 0.1],
        ['Fresa', 32, 0.7, 7.7, 0.3],
        ['Arándanos', 57, 0.7, 14.5, 0.3],
        ['Uvas', 67, 0.6, 17.2, 0.4],
        ['Sandía', 30, 0.6, 7.6, 0.2],
        ['Melón', 34, 0.8, 8.1, 0.2],
        ['Mango', 60, 0.8, 15, 0.4],
        ['Melocotón / Durazno', 39, 0.9, 9.5, 0.3],

        // Frutos Secos y Semillas
        ['Almendras', 579, 21.2, 21.6, 49.9],
        ['Nueces', 654, 15.2, 13.7, 65.2],
        ['Avellanas', 628, 15, 16.7, 60.8],
        ['Cacahuetes / Maní', 567, 25.8, 16.1, 49.2],
        ['Pistachos', 562, 20.2, 27.5, 45.3],
        ['Mantequilla de cacahuete', 588, 25, 20, 50],
        ['Semillas de chía', 486, 16.5, 42.1, 30.7],
        ['Semillas de calabaza', 559, 30.2, 10.7, 49.1],
        ['Aceite de oliva', 884, 0, 0, 100],
        ['Aceite de coco', 862, 0, 0, 100],

        // Bebidas y Suplementos
        ['Café solo', 1, 0.1, 0, 0],
        ['Té verde', 1, 0.2, 0, 0],
        ['Proteína de suero (Whey)', 379, 78, 8, 4],
        ['Creatina monohidratada', 0, 0, 0, 0],

        // Otros
        ['Chocolate negro (>85%)', 598, 7.8, 46, 43],
        ['Miel', 304, 0.3, 82.4, 0],
        ['Azúcar blanco', 387, 0, 100, 0],
        ['Hummous', 166, 7.9, 14.3, 9.6],
    ];

    $stmt = $db->prepare("INSERT INTO food_catalog (name, calories_100g, protein_100g, carbs_100g, fat_100g) VALUES (?, ?, ?, ?, ?)");
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
