<?php
require_once __DIR__ . '/_db.php';
fp_cors();

$env  = getenv('VERCEL_ENV') ?: 'local';
$host = getenv('PGHOST')          ?: getenv('POSTGRES_HOST');
$user = getenv('PGUSER')          ?: getenv('POSTGRES_USER');
$pass = getenv('PGPASSWORD')      ?: getenv('POSTGRES_PASSWORD');
$db   = getenv('PGDATABASE')      ?: getenv('POSTGRES_DATABASE');
$port = getenv('PGPORT')          ?: '5432';

$original_db = $db;
if ($env !== 'production' && ($db === 'neondb' || empty($db))) {
    $db = 'fitpaisa_testing';
}

$results = [
    'env' => $env,
    'host' => $host ? 'Configurado' : 'MISSING',
    'user' => $user ? 'Configurado' : 'MISSING',
    'pass' => $pass ? 'Configurado' : 'MISSING',
    'db_original' => $original_db,
    'db_final' => $db,
    'connection' => 'Testing...'
];

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";
    $test_pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    $results['connection'] = 'SUCCESS';
    
    // Verificar si la tabla de usuarios existe
    $stmt = $test_pdo->query("SELECT to_regclass('public.users') as exists");
    $row = $stmt->fetch();
    $results['tables_ok'] = ($row['exists'] !== null);
    
} catch (PDOException $e) {
    $results['connection'] = 'FAILED: ' . $e->getMessage();
    $results['tables_ok'] = false;
}

fp_success($results);
