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
 * Sanitiza y valida un string de entrada para prevenir XSS.
 *
 * @param mixed  $value   Valor a sanitizar.
 * @param int    $maxLen  Longitud máxima permitida.
 * @return string         String limpio.
 */
function fp_sanitize(mixed $value, int $maxLen = 255): string
{
    $str = trim((string) $value);
    $str = strip_tags($str);
    $str = htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return mb_substr($str, 0, $maxLen);
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
        $db->exec("ALTER TABLE food_entries ADD COLUMN IF NOT EXISTS portion_unit VARCHAR(20) DEFAULT 'g'");
        $db->exec("ALTER TABLE food_entries ADD COLUMN IF NOT EXISTS unit_size VARCHAR(20)");
        
        // 4. Enriquecer datos existentes (Una sola vez o si están NULL)
        // Huevos
        $db->exec("UPDATE food_catalog SET unit_name = 'Huevo', weight_std = 50, weight_small = 40, weight_medium = 50, weight_large = 60 
                   WHERE (name ILIKE '%huevo%entero%' OR name = 'Huevo') AND unit_name IS NULL");
        
        // Pan
        $db->exec("UPDATE food_catalog SET unit_name = 'Rebanada', weight_std = 30 
                   WHERE name ILIKE '%pan%' AND unit_name IS NULL");
        
        // Alitas y Yemas
        $db->exec("UPDATE food_catalog SET unit_name = 'Ala', weight_std = 35 WHERE name ILIKE '%alita%' AND unit_name IS NULL");
        $db->exec("UPDATE food_catalog SET unit_name = 'Yema', weight_std = 17 WHERE name ILIKE '%yema%' AND unit_name IS NULL");

        // Líquidos (Marcar flag is_liquid)
        $db->exec("UPDATE food_catalog SET is_liquid = TRUE 
                   WHERE (name ILIKE '%leche%' OR name ILIKE '%aceite%' OR name ILIKE '%vino%' OR name ILIKE '%bebida%' OR name ILIKE '%zumo%') 
                   AND is_liquid = FALSE");
        
    } catch (PDOException $e) {
        error_log('[FitPaisa][SCHEMA] Fallo en auto-migración: ' . $e->getMessage());
    }
}
