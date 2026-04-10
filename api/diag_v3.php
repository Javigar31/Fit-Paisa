<?php
/**
 * FitPaisa — Diagnóstico Maestro de Producción (V3)
 *
 * Este script realiza una inspección profunda del entorno de producción para
 * identificar la causa de errores 500.
 */
declare(strict_types=1);

// Forzar visualización de errores solo para diagnóstico
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "--- FITPAISA SYSTEM DIAGNOSTIC (V3) ---\n";
echo "Fecha Servidor: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Interface: " . php_sapi_name() . "\n";

/* ── 1. Verificación de Extensiones ── */
echo "\n[1] EXTENSIONES CRÍTICAS:\n";
$extensions = ['pdo', 'pdo_pgsql', 'pgsql', 'openssl', 'json', 'hash'];
foreach ($extensions as $ext) {
    echo "  - " . str_pad($ext, 12) . ": " . (extension_loaded($ext) ? "✓ CARGADA" : "✗ NO ENCONTRADA") . "\n";
}

/* ── 2. Verificación de Variables de Entorno ── */
echo "\n[2] VARIABLES DE ENTORNO (Masked):\n";
$env = getenv('VERCEL_ENV') ?: 'local';
echo "  - VERCEL_ENV: $env\n";

$vars_to_check = [
    'PGHOST_PROD', 'PGUSER_PROD', 'PGDATABASE_PROD', 'PGPASSWORD_PROD',
    'PGHOST', 'PGUSER', 'PGDATABASE', 'PGPASSWORD',
    'POSTGRES_HOST', 'POSTGRES_USER', 'POSTGRES_DATABASE', 'POSTGRES_PASSWORD',
    'DB_PASSWORD_NUEVA', 'JWT_SECRET'
];

foreach ($vars_to_check as $var) {
    $val = getenv($var);
    if ($val) {
        $masked = substr($val, 0, 2) . '...' . substr($val, -2);
        if (strlen($val) < 4) $masked = '***';
        echo "  - " . str_pad($var, 20) . ": ✓ PRESENTE ($masked)\n";
    } else {
        echo "  - " . str_pad($var, 20) . ": ✗ FALTANTE\n";
    }
}

/* ── 3. Prueba de Carga de Módulos Locales ── */
echo "\n[3] CARGA DE MÓDULOS INTERNOS:\n";
$files = ['_db.php', '_jwt.php'];
foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        try {
            require_once $path;
            echo "  - " . str_pad($file, 12) . ": ✓ CARGADO\n";
        } catch (Throwable $e) {
            echo "  - " . str_pad($file, 12) . ": ✗ ERROR AL CARGAR (" . $e->getMessage() . ")\n";
        }
    } else {
        echo "  - " . str_pad($file, 12) . ": ✗ ARCHIVO NO EXISTE\n";
    }
}

/* ── 4. Prueba de Conexión Manual (Aislamiento y Detalle) ── */
echo "\n[4] AISLAMIENTO: TEST DE CONEXIÓN MANUAL:\n";
$env = getenv('VERCEL_ENV') ?: 'local';
if ($env === 'production') {
    $host = getenv('PGHOST_PROD')     ?: getenv('POSTGRES_HOST');
    $user = getenv('PGUSER_PROD')     ?: getenv('POSTGRES_USER');
    $pass = getenv('PGPASSWORD_PROD') ?: getenv('POSTGRES_PASSWORD') ?: getenv('DB_PASSWORD_NUEVA');
    $db_name   = getenv('PGDATABASE_PROD') ?: 'neondb';
} else {
    $host = getenv('PGHOST')          ?: getenv('POSTGRES_HOST');
    $user = getenv('PGUSER')          ?: getenv('POSTGRES_USER');
    $pass = getenv('DB_PASSWORD_NUEVA') ?: getenv('PGPASSWORD') ?: getenv('POSTGRES_PASSWORD');
    $db_name   = getenv('PGDATABASE')      ?: 'fitpaisa_testing';
}

if ($host && $user && $pass && $db_name) {
    $dsn = "pgsql:host={$host};port=5432;dbname={$db_name};sslmode=require";
    echo "  Intentando DSN: " . str_replace($host, 'HIDDEN_HOST', $dsn) . "\n";
    try {
        $start = microtime(true);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
        $end = microtime(true);
        echo "  ✓ ÉXITO MANUAL: Conexión establecida en " . round(($end - $start) * 1000, 2) . "ms\n";
        
        /* ── 5. Verificación de Esquema (Si conectó) ── */
        echo "\n[5] VERIFICACIÓN DE ESQUEMA (Tabla 'users'):\n";
        try {
            $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'users' ORDER BY ordinal_position");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($columns)) {
                echo "  ✗ ERROR: La tabla 'users' no existe en '$db_name'.\n";
                echo "    Tablas disponibles:\n";
                $stmtTables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
                foreach ($stmtTables->fetchAll(PDO::FETCH_COLUMN) as $t) {
                    echo "      - $t\n";
                }
            } else {
                echo "  ✓ La tabla 'users' existe con " . count($columns) . " columnas.\n";
                $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                echo "    Usuarios registrados: $count\n";
            }
        } catch (Throwable $e) {
            echo "  ✗ ERROR Esquema: " . $e->getMessage() . "\n";
        }
    } catch (Throwable $e) {
        echo "  ✗ FALLO MANUAL CRÍTICO: " . $e->getMessage() . "\n";
        echo "    Detalle: Probablemente las credenciales no son válidas para el host '$host' o la DB '$db_name'.\n";
    }
} else {
    echo "  ✗ Credenciales incompletas para prueba manual.\n";
}

/* ── 6. Prueba de Conexión Aplicación (fp_db) ── */
echo "\n[6] TEST DE CONEXIÓN (vía fp_db()):\n";
if (function_exists('fp_db')) {
    try {
        // Obtenemos la instancia pero silenciamos el error si ocurre para que el script termine
        $db = @fp_db();
        echo "  ✓ ÉXITO: fp_db() cargado.\n";
    } catch (Throwable $e) {
        echo "  ✗ fp_db() falló: " . $e->getMessage() . "\n";
    }
} else {
    echo "  ✗ fp_db() no disponible.\n";
}

/* ── Finalización ── */
echo "\n--- FIN DEL DIAGNÓSTICO ---\n";
