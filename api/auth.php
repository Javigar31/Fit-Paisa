<?php
/**
 * FitPaisa — Endpoint de Autenticación
 *
 * Gestiona el registro de nuevos usuarios y el inicio de sesión.
 * Todas las operaciones usan sentencias preparadas PDO para prevenir SQL Injection.
 * Las contraseñas se almacenan con password_hash() BCRYPT cost-12.
 *
 * Rutas disponibles:
 *   POST /api/auth.php?action=register  → Registro de nuevo usuario (4 pasos del frontend)
 *   POST /api/auth.php?action=login     → Inicio de sesión
 *   GET  /api/auth.php?action=me        → Datos del usuario autenticado (requiere JWT)
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 * @version  1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_jwt.php';

fp_cors();

$action = fp_sanitize($_GET['action'] ?? '', 32);

match ($action) {
    'register' => handle_register(),
    'login'    => handle_login(),
    'me'       => handle_me(),
    default    => fp_error(400, "Acción '{$action}' no reconocida."),
};

/* ══════════════════════════════════════════════════════════════════════
   REGISTRO
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Registra un nuevo usuario con perfil físico inicial.
 *
 * Espera un JSON body con:
 *   name, email, phone, password, gender, objective, weight, height
 *
 * El paso de pago del frontend se registra como suscripción FREE hasta
 * que la pasarela confirme el cobro vía webhook.
 *
 * @return never
 */
function handle_register(): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }

    $body = fp_json_body();

    /* ── Validar y sanitizar campos obligatorios ── */
    $name       = fp_sanitize($body['name']      ?? '', 200);
    $email      = fp_sanitize($body['email']     ?? '', 150);
    $phone      = fp_sanitize($body['phone']     ?? '', 30);
    $password   = $body['password'] ?? '';            /* No sanitizar: solo hashear */
    $gender     = fp_sanitize($body['gender']    ?? '', 10);
    $objectiveInput  = fp_sanitize($body['objective'] ?? '', 30);
    $rawWeight       = (float) ($body['weight'] ?? 0);
    $rawHeight       = (float) ($body['height'] ?? 0);
    $plan            = fp_sanitize($body['plan'] ?? 'FREE', 20);

    if (!in_array($plan, ['FREE', 'PREMIUM_MONTHLY', 'PREMIUM_ANNUAL'], true)) {
        $plan = 'FREE';
    }

    /* ── VALIDACIONES DE NEGOCIO ── */
    $errors = [];

    if (empty($name) || preg_match('/[0-9]/', $name)) {
        $errors[] = 'El nombre no puede estar vacío ni contener números.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El formato del correo electrónico es inválido.';
    }
    if (!preg_match('/^[0-9\s+\-]{7,20}$/', $phone)) {
        $errors[] = 'El teléfono es inválido.';
    }
    /* Password: mínimo 8 chars, mayúscula, número y símbolo */
    if (!preg_match('/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/', $password)) {
        $errors[] = 'La contraseña debe tener mínimo 8 caracteres, una mayúscula, un número y un símbolo.';
    }
    if (!in_array($gender, ['MALE', 'FEMALE', 'OTHER'], true)) {
        $errors[] = 'El sexo seleccionado no es válido.';
    }

    /* ── Valores por defecto para registro en 2 pasos ── */
    /* Tanto Free como Premium configuran su peso/altura después de registrarse. */
    $objective = 'MAINTAIN';
    $weight    = 0.01;
    $height    = 0.01;

    // Solo validamos si por alguna razón logran enviarlos (post-registro o manual)
    if ($rawWeight > 0) {
        if ($rawWeight > 500) $errors[] = 'El peso debe ser menor a 500 kg.';
        else $weight = $rawWeight;
    }
    if ($rawHeight > 0) {
        if ($rawHeight > 300) $errors[] = 'La altura debe ser menor a 300 cm.';
        else $height = $rawHeight;
    }
    if (!empty($objectiveInput) && in_array($objectiveInput, ['LOSE_WEIGHT', 'GAIN_MUSCLE', 'MAINTAIN', 'IMPROVE_HEALTH'], true)) {
        $objective = $objectiveInput;
    }

    if (!empty($errors)) {
        fp_error(400, implode(' | ', $errors));
    }

    $db = fp_db();

    /* ── Verificar duplicado de email ── */
    $exists = fp_query(
        'SELECT 1 FROM users WHERE email = :email LIMIT 1',
        [':email' => strtolower($email)]
    )->fetchColumn();

    if ($exists) {
        fp_error(409, 'Ya existe una cuenta con ese correo electrónico.');
    }

    /* ── Hash de contraseña BCRYPT cost-12 ── */
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    if ($hash === false) {
        error_log('[FitPaisa][AUTH] password_hash falló.');
        fp_error(500, 'Error interno del servidor.');
    }

    /* ── Transacción: crear usuario + perfil ── */
    $db->beginTransaction();
    try {
        /* Crear usuario */
        try {
            $stmt = $db->prepare("
                INSERT INTO users (email, password_hash, full_name, phone, role, is_active, created_at)
                VALUES (:email, :hash, :name, :phone, 'USER', TRUE, NOW())
                RETURNING user_id, email, full_name, role, created_at
            ");
            $stmt->execute([
                ':email' => strtolower($email),
                ':hash'  => $hash,
                ':name'  => $name,
                ':phone' => $phone,
            ]);
            $user = $stmt->fetch();
        } catch (Exception $e) {
            throw new Exception("Error inserting user: " . $e->getMessage());
        }

        if (!$user) {
            throw new RuntimeException('No se pudo crear el usuario.');
        }

        /* Calcular edad aproximada desde peso/altura (no se captura en el form) */
        $age = (int) ($body['age'] ?? 25);
        if ($age <= 0 || $age > 120) {
            $age = 25;
        }

        $activity = fp_sanitize($body['activity_level'] ?? 'MODERATE', 20);
        if (!in_array($activity, ['SEDENTARY', 'LIGHT', 'MODERATE', 'ACTIVE', 'VERY_ACTIVE'], true)) {
            $activity = 'MODERATE';
        }

        /* Crear perfil físico */
        $weightToSave = ($weight <= 0) ? 0.01 : $weight;
        $heightToSave = ($height <= 0) ? 0.01 : $height;

        try {
            $stmtProf = $db->prepare("
                INSERT INTO profiles (user_id, weight, height, age, gender, objective, activity_level, updated_at)
                VALUES (CAST(:uid AS integer), CAST(:weight AS numeric), CAST(:height AS numeric), CAST(:age AS smallint), CAST(:gender AS gender_type), CAST(:objective AS objective_type), CAST(:activity AS activity_level), NOW())
            ");
            if (!$stmtProf->execute([
                ':uid'       => $user['user_id'],
                ':weight'    => $weightToSave,
                ':height'    => $heightToSave,
                ':age'       => $age,
                ':gender'    => $gender,
                ':objective' => $objective,
                ':activity'  => $activity,
            ])) {
                $err = $stmtProf->errorInfo();
                throw new Exception("Silent fail in profiles: " . json_encode($err));
            }
            
            /* Debug check for aborted transaction */
            if ($db->query("SELECT 1") === false) {
                throw new Exception("Transaction silently aborted by Postgres during profiles insert! " . json_encode($db->errorInfo()));
            }
        } catch (Exception $e) {
            throw new Exception("Error inserting profile: " . $e->getMessage());
        }

        /* Crear suscripción inicial */
        $amount = ($plan === 'FREE') ? 0.0 : 14.99;
        try {
            // Evaluamos la fecha en PHP para evitar cálculos raros en la base de datos de Postgres y posibles casts opacos
            $endDate = date('Y-m-d', strtotime('+1 month'));

            // Construir el INSERT lo más tonto posible (todo parametrizado y parseado desde PHP)
            $stmtSub = $db->prepare("
                INSERT INTO subscriptions (user_id, plan_type, status, start_date, end_date, amount)
                VALUES (:uid, :plan::subscription_plan, 'ACTIVE', CURRENT_DATE, :end_date::date, :amount::numeric)
            ");
            
            if (!$stmtSub->execute([
                ':uid'      => $user['user_id'],
                ':plan'     => $plan,
                ':end_date' => $endDate,
                ':amount'   => $amount
            ])) {
                $err = $stmtSub->errorInfo();
                throw new Exception("Silent fail in subscriptions: " . json_encode($err));
            }
        } catch (Exception $e) {
            throw new Exception("Error inserting subscription: " . $e->getMessage() . " | Debug Info: user_id=" . $user['user_id'] . " plan=" . $plan);
        }

        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[FitPaisa][AUTH] Error SQL en handle_register: ' . $e->getMessage());
        $messageForUser = 'Error en base de datos: ' . $e->getMessage();
        fp_error(500, $messageForUser);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[FitPaisa][AUTH] Exception en handle_register: ' . $e->getMessage());
        fp_error(500, $e->getMessage());
    }

    /* ── Generar JWT ── */
    $token = jwt_create([
        'user_id' => $user['user_id'],
        'email'   => $user['email'],
        'role'    => $user['role'],
        'name'    => $user['full_name'],
    ]);

    fp_success([
        'token' => $token,
        'user'  => [
            'user_id'           => $user['user_id'],
            'email'             => $user['email'],
            'name'              => $user['full_name'],
            'role'              => $user['role'],
            'created_at'        => $user['created_at'],
            'subscription_plan' => $plan,
        ],
    ], 201);
}

/* ══════════════════════════════════════════════════════════════════════
   LOGIN
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Autentica a un usuario existente.
 *
 * Implementa el algoritmo descrito en DAS §4.1.3:
 *  1. Buscar por email
 *  2. Verificar is_active
 *  3. Verificar bloqueo temporal
 *  4. Comparar hash con bcrypt
 *  5. Controlar intentos fallidos (máx. 5 → bloqueo 15 min)
 *  6. Generar JWT con {user_id, email, role, exp}
 *
 * El mensaje de error es siempre genérico (no revela si el email existe).
 *
 * @return never
 */
function handle_login(): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fp_error(405, 'Método no permitido.');
    }

    $body     = fp_json_body();
    $email    = strtolower(fp_sanitize($body['email']    ?? '', 150));
    $password = $body['password'] ?? '';

    if (empty($email) || empty($password)) {
        fp_error(400, 'El correo electrónico y la contraseña son obligatorios.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fp_error(400, 'Formato de correo electrónico inválido.');
    }

    /* ── Buscar usuario y su suscripción ── */
    $user = fp_query(
        "SELECT u.user_id, u.email, u.password_hash, u.full_name, u.role, u.is_active,
                u.login_attempts, u.locked_until, u.last_login,
                s.plan_type AS subscription_plan
         FROM users u
         LEFT JOIN subscriptions s ON s.user_id = u.user_id AND s.status = 'ACTIVE'
         WHERE u.email = :email LIMIT 1",
        [':email' => $email]
    )->fetch();

    /* Error genérico para no revelar si el email existe */
    if (!$user) {
        /* Simular tiempo de bcrypt para evitar timing oracle */
        password_verify('dummy', '$2y$12$invalidhashinvalidhashinvalidhashinval');
        fp_error(401, 'Correo electrónico o contraseña incorrectos.');
    }

    /* ── Verificar cuenta activa ── */
    if (!$user['is_active']) {
        fp_error(403, 'Tu cuenta ha sido desactivada. Contacta al soporte.');
    }

    /* ── Verificar bloqueo temporal ── */
    if ($user['locked_until'] !== null) {
        $lockedUntil = new DateTimeImmutable($user['locked_until']);
        if ($lockedUntil > new DateTimeImmutable()) {
            $remaining = ceil(($lockedUntil->getTimestamp() - time()) / 60);
            fp_error(403, "Cuenta bloqueada por exceso de intentos. Espera {$remaining} minuto(s).");
        }
        /* Desbloquear si el tiempo ya pasó */
        fp_query(
            'UPDATE users SET login_attempts = 0, locked_until = NULL WHERE user_id = :id',
            [':id' => $user['user_id']]
        );
    }

    /* ── Verificar contraseña ── */
    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int) $user['login_attempts'] + 1;

        if ($attempts >= 5) {
            /* Bloquear 15 minutos */
            fp_query(
                "UPDATE users SET login_attempts = :att, locked_until = NOW() + INTERVAL '15 minutes'
                 WHERE user_id = :id",
                [':att' => $attempts, ':id' => $user['user_id']]
            );
            fp_error(403, 'Demasiados intentos fallidos. Cuenta bloqueada 15 minutos.');
        }

        fp_query(
            'UPDATE users SET login_attempts = :att WHERE user_id = :id',
            [':att' => $attempts, ':id' => $user['user_id']]
        );

        fp_error(401, 'Correo electrónico o contraseña incorrectos.');
    }

    /* ── Login exitoso: resetear intentos y actualizar last_login ── */
    fp_query(
        'UPDATE users SET login_attempts = 0, locked_until = NULL, last_login = NOW()
         WHERE user_id = :id',
        [':id' => $user['user_id']]
    );

    /* ── Rehash si es necesario (mejora de cost en futuro) ── */
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($newHash !== false) {
            fp_query(
                'UPDATE users SET password_hash = :hash WHERE user_id = :id',
                [':hash' => $newHash, ':id' => $user['user_id']]
            );
        }
    }

    /* ── Generar JWT ── */
    $token = jwt_create([
        'user_id' => $user['user_id'],
        'email'   => $user['email'],
        'role'    => $user['role'],
        'name'    => $user['full_name'],
    ]);

    fp_success([
        'token' => $token,
        'user'  => [
            'user_id'           => $user['user_id'],
            'email'             => $user['email'],
            'name'              => $user['full_name'],
            'role'              => $user['role'],
            'last_login'        => $user['last_login'],
            'subscription_plan' => $user['subscription_plan'] ?? 'FREE',
        ],
    ]);
}

/* ══════════════════════════════════════════════════════════════════════
   ME — Datos del usuario autenticado
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Retorna los datos del usuario identificado por el JWT Bearer.
 *
 * @return never
 */
function handle_me(): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        fp_error(405, 'Método no permitido.');
    }

    $payload = jwt_require(); /* Aborta con 401 si no hay token válido */

    $user = fp_query(
        "SELECT u.user_id, u.email, u.full_name, u.phone, u.role, u.created_at,
                p.profile_id, p.weight, p.height, p.age, p.gender, p.objective, p.activity_level,
                s.plan_type AS subscription_plan
         FROM users u
         LEFT JOIN profiles p ON p.user_id = u.user_id
         LEFT JOIN subscriptions s ON s.user_id = u.user_id AND s.status = 'ACTIVE'
         WHERE u.user_id = :id AND u.is_active = TRUE
         LIMIT 1",
        [':id' => $payload['user_id']]
    )->fetch();

    if (!$user) {
        fp_error(404, 'Usuario no encontrado o inactivo.');
    }

    fp_success(['user' => $user]);
}
