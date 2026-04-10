<?php
/**
 * FitPaisa — Script de Diagnóstico de Conexión
 */
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "--- FITPAISA DB DIAGNOSTIC ---\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";

$info = fp_env_info();
echo "Entorno Detectado: " . $info['env'] . "\n";
echo "Base de Datos en Env: " . ($info['database'] ?: '(vacío)') . "\n";

// No mostramos los valores reales por seguridad, solo si existen
echo "Comprobando variables:\n";
echo "PGHOST: " . (getenv('PGHOST') ? 'SÍ' : 'NO') . "\n";
echo "POSTGRES_HOST: " . (getenv('POSTGRES_HOST') ? 'SÍ' : 'NO') . "\n";
echo "PGUSER: " . (getenv('PGUSER') ? 'SÍ' : 'NO') . "\n";
echo "PGPASSWORD: " . (getenv('PGPASSWORD') ? 'SÍ' : 'NO') . "\n";
echo "DB_PASSWORD_NUEVA: " . (getenv('DB_PASSWORD_NUEVA') ? 'SÍ' : 'NO') . "\n";
echo "PGDATABASE: " . (getenv('PGDATABASE') ? 'SÍ' : 'NO') . "\n";

echo "\nIntentando conectar vía PDO (MANUAL)...\n";

$host = getenv('PGHOST')          ?: getenv('POSTGRES_HOST');
$user = getenv('PGUSER')          ?: getenv('POSTGRES_USER');
$pass = getenv('PGPASSWORD')      ?: getenv('DB_PASSWORD_NUEVA');
$db   = getenv('PGDATABASE')      ?: getenv('POSTGRES_DATABASE');
$port = getenv('PGPORT')          ?: '5432';

echo "Host: $host | User: $user | DB: $db | Port: $port\n";

$dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => true,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
    echo "✓ CONEXIÓN MANUAL EXITOSA.\n";
    $stmt = $pdo->query("SELECT version()");
    echo "Versión de DB: " . $stmt->fetchColumn() . "\n";
} catch (PDOException $e) {
    echo "✗ ERROR DE CONEXIÓN MANUAL:\n";
    echo $e->getMessage() . "\n";
    echo "\nIntentando VARIACIÓN 2 (sin sslmode)...\n";
    try {
        $dsn2 = "pgsql:host={$host};port={$port};dbname={$db}";
        $pdo2 = new PDO($dsn2, $user, $pass, [PDO::ATTR_TIMEOUT => 5]);
        echo "✓ CONEXIÓN SIN SSL EXITOSA.\n";
    } catch (PDOException $e2) {
        echo "✗ ERROR VARIACIÓN 2: " . $e2->getMessage() . "\n";
    }
}

echo "\n--- FIN DEL DIAGNÓSTICO ---\n";
