<?php
/**
 * FitPaisa — Módulo de Conexión a Base de Datos
 *
 * Proporciona una conexión PDO singleton a Neon PostgreSQL.
 * Lee las credenciales exclusivamente desde variables de entorno de Vercel.
 * NUNCA expone información técnica (credenciales, stack traces) al cliente final.
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 * @version  1.0.0
 */

declare(strict_types=1);

/** Instancia PDO compartida en el ciclo de vida de la función serverless */
$_fp_pdo = null;

// Seguridad: Cabeceras globales obligatorias
fp_secure_headers();

/**
 * Retorna (o crea) la conexión PDO a Neon PostgreSQL.
 *
 * Utiliza las variables de entorno inyectadas por Vercel:
 *  - PGHOST / POSTGRES_HOST     → host del pooler de Neon
 *  - PGUSER / POSTGRES_USER     → usuario de la BD
 *  - PGPASSWORD / DB_PASSWORD_NUEVA → contraseña
 *  - PGDATABASE / POSTGRES_DATABASE → nombre de la base de datos
 *
 * @throws RuntimeException Si no se pueden leer las credenciales o la conexión falla.
 * @return PDO Objeto de conexión con errMode EXCEPTION activo.
 */
function fp_db(): PDO
{
    global $_fp_pdo;
    if ($_fp_pdo instanceof PDO) return $_fp_pdo;

    $config = _fp_get_db_config();
    $host = $config['host'];
    $user = $config['user'];
    $pass = $config['pass'];
    $db   = $config['db'];
    $port = $config['port'];
    $env  = $config['env'];


    if (!$host || !$user || !$pass || !$db) {
        /* Loguear el problema internamente sin revelar detalles al exterior */
        error_log("[FitPaisa][DB] Credenciales incompletas (Env: $env). Host: $host, DB: $db");
        fp_error(500, 'Error interno del servidor. Credenciales de base de datos no configuradas.');
    }

    $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";

    try {
        $_fp_pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            /* Neon pooler/PgBouncer no se lleva bien con server-side prepares en flujos transaccionales. */
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
        /* Asegurar que el esquema esté actualizado en este entorno (Auto-Migración) */
        fp_ensure_schema($_fp_pdo);
    } catch (PDOException $e) {
        /* Solo el log interno contiene el motivo real */
        error_log('[FitPaisa][DB] Fallo de conexión: ' . $e->getMessage());
        fp_error(500, 'Error interno del servidor. Contacta al administrador.');
    }

    return $_fp_pdo;
}

/**
 * Ejecuta una sentencia preparada y retorna el statement.
 *
 * Encapsula toda query para garantizar el uso de prepared statements.
 * Los parámetros se pasan siempre como array, nunca interpolados.
 *
 * @param string $sql    Consulta SQL con placeholders (:nombre o ?).
 * @param array  $params Parámetros para la sentencia preparada.
 * @return PDOStatement  Statement ejecutado, listo para fetch.
 */
function fp_query(string $sql, array $params = []): PDOStatement
{
    try {
        $stmt = fp_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log('[FitPaisa][QUERY] ' . $e->getMessage() . ' | SQL: ' . $sql);
        fp_error(500, 'Error interno del servidor.');
    }
}

/**
 * Devuelve una respuesta JSON de error y detiene la ejecución.
 *
 * Garantiza que NINGÚN mensaje de error interno (SQL, stack trace, credenciales)
 * llegue al cliente. El mensaje es siempre genérico y controlado.
 *
 * @param int    $code    Código HTTP de respuesta (ej. 400, 401, 403, 500).
 * @param string $message Mensaje legible por el usuario final.
 * @return never
 */
function fp_error(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Devuelve una respuesta JSON de éxito.
 *
 * @param array $data Datos a incluir en la respuesta.
 * @param int   $code Código HTTP (200 por defecto).
 * @return never
 */
function fp_success(array $data = [], int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Sanitiza una entrada de forma robusta según su tipo esperado.
 *
 * @param mixed  $value   Valor a sanitizar.
 * @param int    $maxLen  Longitud máxima permitida.
 * @param string $type    Tipo esperado: 'string', 'email', 'int', 'float', 'slug'.
 * @return mixed          Valor limpio.
 */
function fp_sanitize(mixed $value, int $maxLen = 255, string $type = 'string'): mixed
{
    $val = trim((string) ($value ?? ''));
    
    switch ($type) {
        case 'email':
            $val = filter_var($val, FILTER_SANITIZE_EMAIL);
            break;
        case 'int':
            return (int) $val;
        case 'float':
            return (float) $val;
        case 'slug':
            $val = preg_replace('/[^a-z0-9\-_]/', '', strtolower($val));
            break;
        default:
            // XSS Prevention: remove tags and encode HTML entities
            $val = strip_tags($val);
            $val = htmlspecialchars($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            break;
    }
    
    return mb_substr($val, 0, $maxLen);
}

/**
 * Añade cabeceras de seguridad HTTP robustas.
 * Se llama al inicio de cada petición.
 */
function fp_secure_headers(): void
{
    if (headers_sent()) return;

    // Prevenir Clickjacking
    header('X-Frame-Options: DENY');
    // Prevenir sniffing de MIME types
    header('X-Content-Type-Options: nosniff');
    // Forzar HTTPS (HSTS) - solo en producción
    $env = getenv('VERCEL_ENV') ?: 'local';
    if ($env === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
    // Protección XSS básica del navegador (obsoleta pero ayuda en navegadores viejos)
    header('X-XSS-Protection: 1; mode=block');
    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Content Security Policy (Básica - ajustar según necesidades)
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self' https://*.vercel.app");
}

/**
 * Controla el límite de peticiones por IP y Endpoint.
 *
 * @param string $endpoint Identificador del recurso (ej: 'auth_login').
 * @param int    $limit    Máximo de peticiones permitidas.
 * @param int    $seconds  Ventana de tiempo en segundos.
 * @return void            Aborta con 429 si se excede el límite.
 */
function fp_rate_limit(string $endpoint, int $limit = 60, int $seconds = 60): void
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // Tomamos solo la primera IP si hay una lista (Vercel/Proxy)
    $ip = trim(explode(',', $ip)[0]);
    $key = hash('sha256', "rate:{$ip}:{$endpoint}");
    
    $now = date('Y-m-d H:i:s');
    
    // 1. Limpiar registros expirados (Opcional: hacerlo periódicamente, aquí por simplicidad)
    // 2. Transacción de chequeo
    try {
        $db = fp_db();
        $record = fp_query(
            "SELECT hits, reset_at FROM rate_limits WHERE rate_key = :key",
            [':key' => $key]
        )->fetch();

        if (!$record) {
            // Primer hit
            $resetAt = date('Y-m-d H:i:s', time() + $seconds);
            fp_query(
                "INSERT INTO rate_limits (rate_key, hits, reset_at) VALUES (:key, 1, :reset)",
                [':key' => $key, ':reset' => $resetAt]
            );
            return;
        }

        if (time() > strtotime($record['reset_at'])) {
            // Ventana expirada: resetear
            $resetAt = date('Y-m-d H:i:s', time() + $seconds);
            fp_query(
                "UPDATE rate_limits SET hits = 1, reset_at = :reset WHERE rate_key = :key",
                [':key' => $key, ':reset' => $resetAt]
            );
            return;
        }

        if ($record['hits'] >= $limit) {
            header('Retry-After: ' . (strtotime($record['reset_at']) - time()));
            fp_error(429, 'Demasiadas peticiones. Por favor, espera un momento.');
        }

        // Incrementar hit
        fp_query(
            "UPDATE rate_limits SET hits = hits + 1 WHERE rate_key = :key",
            [':key' => $key]
        );

    } catch (Exception $e) {
        // En caso de error de BD en rate limiting, fallar silenciosamente (fail-open) para no romper la app
        error_log("[FitPaisa][RATE_LIMIT] " . $e->getMessage());
    }
}

/**
 * Lee el cuerpo JSON de la petición entrante de forma segura.
 *
 * @return array Datos decodificados o array vacío si el body es inválido.
 */
function fp_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Configura las cabeceras CORS permitiendo el origen de Vercel.
 * Solo se llama desde endpoints que lo necesiten.
 *
 * @return void
 */
function fp_cors(): void
{
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = [
        'https://fit-paisa.vercel.app',
        'http://localhost:3000',
        'http://localhost',
    ];

    $isVercel = str_ends_with($origin, '.vercel.app');

    if (in_array($origin, $allowed, true) || $isVercel) {
        header("Access-Control-Allow-Origin: {$origin}");
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Retorna información sobre el entorno actual usando la lógica centralizada.
 */
function fp_env_info(): array
{
    $config = _fp_get_db_config();
    return [
        'env'           => $config['env'],
        'database'      => $config['db'],
        'is_production' => ($config['env'] === 'production')
    ];
}

/**
 * Función interna para centralizar la resolución de credenciales.
 */
function _fp_get_db_config(): array
{
    $env = getenv('VERCEL_ENV') ?: 'local';
    
    if ($env === 'production') {
        return [
            'host' => getenv('PGHOST_PROD')     ?: getenv('POSTGRES_HOST'),
            'user' => getenv('PGUSER_PROD')     ?: getenv('POSTGRES_USER'),
            'pass' => getenv('PGPASSWORD_PROD') ?: getenv('DB_PASSWORD_NUEVA') ?: getenv('POSTGRES_PASSWORD'),
            'db'   => getenv('PGDATABASE_PROD') ?: 'neondb',
            'port' => getenv('PGPORT') ?: '5432',
            'env'  => 'production'
        ];
    } else {
        // Testing (preview) o Local
        $db = getenv('PGDATABASE') ?: getenv('POSTGRES_DATABASE');
        
        // REGLA DE ORO: Si no estamos en producción, forzamos fitpaisa_testing 
        // si la BD detectada es la default 'neondb' o si estamos en el entorno 'preview'.
        if ($db === 'neondb' || empty($db) || $env === 'preview') {
            $db = 'fitpaisa_testing';
        }

        return [
            'host' => getenv('PGHOST')          ?: getenv('POSTGRES_HOST'),
            'user' => getenv('PGUSER')          ?: getenv('POSTGRES_USER'),
            'pass' => getenv('DB_PASSWORD_NUEVA') ?: getenv('PGPASSWORD') ?: getenv('POSTGRES_PASSWORD'),
            'db'   => $db,
            'port' => getenv('PGPORT') ?: '5432',
            'env'  => $env
        ];
    }
}
/**
 * Asegura que las tablas y columnas necesarias existan (Auto-Migración Idempotente).
 * Se ejecuta en cada nueva conexión de forma ligera mediante 'IF NOT EXISTS'.
 */
function fp_ensure_schema(PDO $db): void
{
    try {
        // 1. Columnas de Objetivos y Ubicación en Profiles
        $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS target_weight DECIMAL(5,2)");
        $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS target_time_weeks SMALLINT");
        $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS timezone VARCHAR(50)");

        // 2. Metadatos de Unidades en food_catalog
        $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS unit_name VARCHAR(50)");
        $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_std DECIMAL(5,2)");
        $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_small DECIMAL(5,2)");
        $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_medium DECIMAL(5,2)");
        $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_large DECIMAL(5,2)");
        $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS is_liquid BOOLEAN DEFAULT FALSE");

        // 3. Metadatos de Unidades en food_entries
        $db->exec("ALTER TABLE food_entries ADD COLUMN IF NOT EXISTS portion_amount DECIMAL(10,2) DEFAULT 100");
        $db->exec("ALTER TABLE food_entries ADD COLUMN IF NOT EXISTS portion_unit VARCHAR(50) DEFAULT 'g'");
        $db->exec("ALTER TABLE food_entries ADD COLUMN IF NOT EXISTS unit_size VARCHAR(20)");

        // 4. Suscripciones - Columnas faltantes para Admin
        $db->exec("ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ DEFAULT NOW()");
        $db->exec("ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS starts_at TIMESTAMPTZ");
        $db->exec("ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS ends_at TIMESTAMPTZ");
        $db->exec("ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS provider VARCHAR(50)");
        
        // Sincronizar datos si las nuevas columnas de timestamp están vacías
        $db->exec("UPDATE subscriptions SET starts_at = start_date::timestamptz WHERE starts_at IS NULL AND start_date IS NOT NULL");
        $db->exec("UPDATE subscriptions SET ends_at = end_date::timestamptz WHERE ends_at IS NULL AND end_date IS NOT NULL");

        // 5. Enriquecer datos existentes (Una sola vez o si están NULL)
        // Huevos
        $db->exec("UPDATE food_catalog SET unit_name = 'Huevo', weight_std = 50, weight_small = 40, weight_medium = 50, weight_large = 60 
                   WHERE (name ILIKE '%huevo%entero%' OR name = 'Huevo') AND unit_name IS NULL");
        
        // Pan
        $db->exec("UPDATE food_catalog SET unit_name = 'Rebanada', weight_std = 30 
                   WHERE name ILIKE '%pan%' AND unit_name IS NULL");
        
        // Alitas y Yemas
        $db->exec("UPDATE food_catalog SET unit_name = 'Ala', weight_std = 35 WHERE name ILIKE '%alita%' AND unit_name IS NULL");
        $db->exec("UPDATE food_catalog SET unit_name = 'Yema', weight_std = 17 WHERE name ILIKE '%yema%' AND unit_name IS NULL");

        // 6. Índices de rendimiento para Admin
        $db->exec("CREATE INDEX IF NOT EXISTS idx_users_role_status ON users(role, is_active)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_users_created_at_desc ON users(created_at DESC)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_subs_status_plan ON subscriptions(status, plan_type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_subs_updated_at ON subscriptions(updated_at, status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_wp_coach_status ON workout_plans(coach_id, status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_subs_user_id ON subscriptions(user_id)");

        // Líquidos (Marcar flag is_liquid)
        $db->exec("UPDATE food_catalog SET is_liquid = TRUE 
                   WHERE (name ILIKE '%leche%' OR name ILIKE '%aceite%' OR name ILIKE '%vino%' OR name ILIKE '%bebida%' OR name ILIKE '%zumo%') 
                   AND is_liquid = FALSE");

        // 7. Rate Limiting Table
        $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            rate_key VARCHAR(255) PRIMARY KEY,
            hits INTEGER DEFAULT 1,
            reset_at TIMESTAMPTZ NOT NULL
        )");
        
    } catch (PDOException $e) {
        error_log('[FitPaisa][SCHEMA] Fallo en auto-migración: ' . $e->getMessage());
    }
}
