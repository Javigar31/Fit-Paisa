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
echo "PGDATABASE: " . (getenv('PGDATABASE') ? 'SÍ' : 'NO') . "\n";

echo "\nIntentando conectar vía PDO...\n";

try {
    $db = fp_db();
    echo "✓ CONEXIÓN EXITOSA.\n";
    
    $stmt = $db->query("SELECT version()");
    echo "Versión de DB: " . $stmt->fetchColumn() . "\n";
    
} catch (Exception $e) {
    echo "✗ ERROR DE CONEXIÓN:\n";
    echo $e->getMessage() . "\n";
    echo "\nDetalles técnicos:\n";
    echo "Código: " . $e->getCode() . "\n";
}

echo "\n--- FIN DEL DIAGNÓSTICO ---\n";
