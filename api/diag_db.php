<?php
/**
 * FitPaisa — Script de Diagnóstico de Conexión (V3)
 */
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "--- FITPAISA DB DIAGNOSTIC (TRIPLE CAPA) ---\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

/* ── 1. Información de Entorno ───────────────────────────────────────── */
$dbUrl = getenv('DATABASE_URL') ?: getenv('POSTGRES_URL');
echo "[ENV] DATABASE_URL Detectada: " . ($dbUrl ? 'SÍ (L:' . strlen($dbUrl) . ')' : 'NO') . "\n";
echo "[ENV] VERCEL_ENV: " . (getenv('VERCEL_ENV') ?: 'local') . "\n";
echo "[ENV] DB_PASSWORD_NUEVA: " . (getenv('DB_PASSWORD_NUEVA') ? 'SÍ' : 'NO') . "\n\n";

/* ── 2. Intento vía URL (Nueva Lógica) ───────────────────────────────── */
echo "CAPA 1: Intento vía DATABASE_URL / POSTGRES_URL\n";
if ($dbUrl) {
    try {
        $p = parse_url($dbUrl);
        $h = $p['host'] ?? '';
        $u = $p['user'] ?? '';
        $pw = $p['pass'] ?? '';
        $db = ltrim($p['path'] ?? '', '/');
        $po = (string)($p['port'] ?? '5432');
        
        echo "   Parseado -> Host: $h | User: $u | DB: $db | Port: $po\n";
        
        $dsn = "pgsql:host=$h;port=$po;dbname=$db;sslmode=require";
        $pdo = new PDO($dsn, $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        echo "   ✓ ÉXITO: Conectado vía URL.\n";
    } catch (PDOException $e) {
        echo "   ✗ FALLO CAPA 1: " . $e->getMessage() . "\n";
    }
} else {
    echo "   (Saltado: No hay URL configurada)\n";
}
echo "\n";

/* ── 3. Intento vía Componentes Manuales ─────────────────────────────── */
echo "CAPA 2: Intento vía Componentes Manuales (Legacy)\n";
$host = getenv('PGHOST')      ?: getenv('POSTGRES_HOST');
$user = getenv('PGUSER')      ?: getenv('POSTGRES_USER');
$pass = getenv('PGPASSWORD')  ?: getenv('DB_PASSWORD_NUEVA');
$db   = getenv('PGDATABASE')  ?: getenv('POSTGRES_DATABASE');
$port = getenv('PGPORT')      ?: '5432';

if ($host && $user && $pass && $db) {
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        echo "   ✓ ÉXITO: Conectado vía Componentes.\n";
    } catch (PDOException $e) {
        echo "   ✗ FALLO CAPA 2: " . $e->getMessage() . "\n";
    }
} else {
    echo "   (Saltado: Faltan variables individuales)\n";
}
echo "\n";

/* ── 4. Intento sin SSL (Diagnóstico de Certificado) ─────────────────── */
echo "CAPA 3: Intento sin SSL (Para descartar fallos de certificado)\n";
if ($host && $user && $pass && $db) {
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$db"; // Sin sslmode=require
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        echo "   ✓ ÉXITO: Conectado sin SSL explicito (Neon podría haberlo forzado igual).\n";
    } catch (PDOException $e) {
        echo "   ✗ FALLO CAPA 3: " . $e->getMessage() . "\n";
    }
} else {
    echo "   (Saltado)\n";
}

echo "\n--- FIN DEL DIAGNÓSTICO ---\n";
