<?php
/**
 * FitPaisa — Script de Diagnóstico de Conexión (V4)
 * (Forzando el uso de variables manuales para esquivar el bloqueo de Vercel)
 */
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "--- FITPAISA DB DIAGNOSTIC (MODO LIBERACIÓN) ---\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

/* ── 1. Información detectada ───────────────────────────────────────── */
$host = getenv('PGHOST')          ?: getenv('POSTGRES_HOST');
$user = getenv('PGUSER')          ?: getenv('POSTGRES_USER');
$pass = getenv('DB_PASSWORD_NUEVA') ?: getenv('PGPASSWORD') ?: getenv('POSTGRES_PASSWORD');
$db   = getenv('PGDATABASE')      ?: getenv('POSTGRES_DATABASE');
$port = getenv('PGPORT')          ?: '5432';

echo "[VARS] Host: $host\n";
echo "[VARS] User: $user\n";
echo "[VARS] DB: $db\n";
echo "[VARS] DB_PASSWORD_NUEVA Detectada: " . (getenv('DB_PASSWORD_NUEVA') ? 'SÍ' : 'NO') . "\n";

$masked = (strlen($pass ?? '') > 6) ? substr($pass, 0, 3) . '...' . substr($pass, -3) : '***';
echo "[VARS] Password ACTUAL en código (máscara): $masked\n\n";

/* ── 2. Intento de Conexión Real con las nuevas variables ──────────── */
echo "INTENTO DE CONEXIÓN CON VARIABLES MANUALES:\n";

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10
    ]);
    echo "   ✓ ÉXITO: ¡Conectado al fin! El código ya usa tus variables nuevas.\n";
    
    $stmt = $pdo->query("SELECT version()");
    echo "   Versión de DB: " . $stmt->fetchColumn() . "\n";
} catch (PDOException $e) {
    echo "   ✗ FALLO: " . $e->getMessage() . "\n";
    echo "\n¿Has hecho el Redeploy en Vercel tras subir este cambio?\n";
}

echo "\n--- FIN DEL DIAGNÓSTICO ---\n";
