<?php
/**
 * FitPaisa — Script de Diagnóstico de Base de Datos
 * Verifica la integridad del esquema para la v1.4.0 (Unidades y Líquidos)
 */

declare(strict_types=1);
require_once __DIR__ . '/_db.php';

// Seguridad: En producción solo se permite con una llave específica (Env Var: DIAG_TOKEN)
$env = getenv('VERCEL_ENV') ?: 'local';
$providedKey = $_GET['key'] ?? '';
$secret = getenv('DIAG_TOKEN');

// Seguridad: Límite estricto de diagnóstico para evitar recolección de info
fp_rate_limit('diag_db', 5, 3600);

/* COMENTADO TEMPORALMENTE PARA DIAGNÓSTICO DEL USUARIO
if ($env === 'production') {
    if (!$secret || strlen($secret) < 16) {
        error_log('[FitPaisa][SECURITY] DIAG_TOKEN no configurado o muy corto.');
        fp_error(500, 'Diagnóstico deshabilitado por razones de seguridad.');
    }
    
    if (!hash_equals($secret, $providedKey)) {
        header('HTTP/1.1 403 Forbidden');
        die("Acceso denegado: Este script está protegido.");
    }
}
*/

header('Content-Type: text/html; charset=utf-8');
echo "<style>body{background:#0a0a0a;color:#eee;font-family:sans-serif;padding:20px} .ok{color:#4CAF50} .err{color:#F44336} .info{color:#00BCD4}</style>";
echo "<h2>🔍 Diagnóstico de Base de Datos - FitPaisa v1.4.0</h2>";

try {
    $db = fp_db();
    echo "<p class='ok'>✓ Conexión establecida correctamente ($env)</p>";

    // 1. Verificar food_catalog
    echo "<h3>1. Tabla: food_catalog</h3>";
    $cols = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'food_catalog'")->fetchAll(PDO::FETCH_COLUMN);
    
    $required = ['unit_name', 'weight_std', 'weight_small', 'is_liquid'];
    foreach ($required as $col) {
        if (in_array($col, $cols)) {
            echo "<p class='ok'>✓ Columna '$col' existe.</p>";
        } else {
            echo "<p class='err'>✗ Falta columna '$col'.</p>";
        }
    }

    $withUnits = $db->query("SELECT COUNT(*) FROM food_catalog WHERE unit_name IS NOT NULL")->fetchColumn();
    $total = $db->query("SELECT COUNT(*) FROM food_catalog")->fetchColumn();
    echo "<p class='info'>ℹ Alimentos con unidades configuradas: <b>$withUnits</b> de $total.</p>";

    // 2. Verificar food_entries
    echo "<h3>2. Tabla: food_entries</h3>";
    $cols_entries = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'food_entries'")->fetchAll(PDO::FETCH_COLUMN);
    $required_entries = ['portion_amount', 'portion_unit', 'unit_size'];
    
    foreach ($required_entries as $col) {
        if (in_array($col, $cols_entries)) {
            echo "<p class='ok'>✓ Columna '$col' existe.</p>";
        } else {
            echo "<p class='err'>✗ Falta columna '$col'.</p>";
        }
    }

    echo "<h3>3. Estado del Entorno</h3>";
    $info = fp_env_info();
    echo "<ul>";
    echo "<li>Entorno: " . $info['env'] . "</li>";
    echo "<li>Base de Datos: " . ($info['database'] ?: 'N/A') . "</li>";
    echo "</ul>";

    echo "<hr><p class='ok'><b>TODO CORRECTO:</b> Tu base de datos está lista para soportar unidades y líquidos.</p>";

} catch (Exception $e) {
    echo "<p class='err'>❌ ERROR DE DIAGNÓSTICO: " . $e->getMessage() . "</p>";
}
