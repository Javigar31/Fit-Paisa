<?php
/**
 * FitPaisa — Diagnóstico Maestro de Producción
 */
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "--- FITPAISA MASTER DIAGNOSTIC ---\n";
echo "Entorno: " . (getenv('VERCEL_ENV') ?: 'local') . "\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Intentamos conectar usando la función oficial del sistema
    $pdo = fp_db();
    echo "✓ ÉXITO TOTAL: La conexión desde Master funciona correctamente.\n";
    
    $stmt = $pdo->query("SELECT current_database(), current_user, version()");
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "   DB Actual: " . $res['current_database'] . "\n";
    echo "   Usuario: " . $res['current_user'] . "\n";
    echo "   Versión: " . substr($res['version'], 0, 30) . "...\n";

} catch (PDOException $e) {
    echo "✗ FALLO DE CONEXIÓN EN MASTER:\n";
    echo "  Error: " . $e->getMessage() . "\n";
    echo "  Código: " . $e->getCode() . "\n\n";
    
    echo "DETALLES RECOLECTADOS (Con máscaras):\n";
    
    // Obtenemos los mismos valores que usa fp_db() para ver qué está fallando
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
    
    $m_pass = (strlen($pass ?? '') > 4) ? substr($pass, 0, 2) . '...' . substr($pass, -2) : '***';
    
    echo "  - Host Detectado: $host\n";
    echo "  - User Detectado: $user\n";
    echo "  - DB Detectada: $db\n";
    echo "  - Pass (máscara): $m_pass\n";
}

echo "\n--- FIN DEL DIAGNÓSTICO ---\n";
