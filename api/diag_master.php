<?php
/**
 * FitPaisa — Diagnóstico Maestro de Producción (V2)
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

echo "--- FITPAISA MASTER DIAGNOSTIC (V2) ---\n";
echo "Entorno Detectado: " . (getenv('VERCEL_ENV') ?: 'local') . "\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

/* ── 1. Verificación de Variables de Entorno ── */
$env = getenv('VERCEL_ENV') ?: 'local';

if ($env === 'production') {
    $host = getenv('PGHOST_PROD')     ?: getenv('POSTGRES_HOST');
    $user = getenv('PGUSER_PROD')     ?: getenv('POSTGRES_USER');
    $pass = getenv('PGPASSWORD_PROD') ?: getenv('POSTGRES_PASSWORD') ?: getenv('DB_PASSWORD_NUEVA');
    $db   = getenv('PGDATABASE_PROD') ?: 'neondb'; 
} else {
    $host = getenv('PGHOST');
    $user = getenv('PGUSER');
    $pass = getenv('DB_PASSWORD_NUEVA') ?: getenv('PGPASSWORD');
    $db   = getenv('PGDATABASE') ?: 'fitpaisa_testing';
}

echo "ESTADO DE VARIABLES EN VERCEL (PRODUCTION):\n";
echo "  - Host: " . ($host ? "SÍ ($host)" : "FALTA ✗") . "\n";
echo "  - User: " . ($user ? "SÍ ($user)" : "FALTA ✗") . "\n";
echo "  - DB:   " . ($db   ? "SÍ ($db)"   : "FALTA ✗") . "\n";
echo "  - Pass: " . ($pass ? "SÍ (" . substr($pass, 0, 3) . "..." . substr($pass, -3) . ")" : "FALTA ✗") . "\n\n";

if (!$host || !$user || !$pass || !$db) {
    echo "¡ERROR!: Faltan credenciales críticas en el entorno de Producción de Vercel.\n";
    echo "Asegúrate de que estas variables estén configuradas para el scope 'Production' en Vercel.\n";
} else {
    echo "Intentando conexión real...\n";
    try {
        $dsn = "pgsql:host=$host;port=5432;dbname=$db;sslmode=require";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        echo "✓ ÉXITO: La conexión manual en Producción funciona perfectamente.\n";
    } catch (PDOException $e) {
        echo "✗ FALLO TÉCNICO: " . $e->getMessage() . "\n";
    }
}

echo "\n--- FIN DEL DIAGNÓSTICO ---\n";
