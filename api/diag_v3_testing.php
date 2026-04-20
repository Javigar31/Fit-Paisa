<?php
/**
 * FitPaisa — Diagnóstico Avanzado v3 (TESTING)
 * Verifica entorno, extensiones y base de datos con prioridad de credenciales.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
echo "<style>
    body{background:#0a0d14;color:#eee;font-family:monaco,consolas,monospace;padding:30px;line-height:1.6}
    .card{background:#161b22;border:1px solid #30363d;padding:20px;border-radius:12px;margin-bottom:20px}
    .ok{color:#7ee787;font-weight:bold}
    .err{color:#ff7b72;font-weight:bold}
    .warn{color:#d2a8ff;font-weight:bold}
    h2{color:#58a6ff;border-bottom:1px solid #30363d;padding-bottom:10px}
    ul{list-style:none;padding:0}
    li{margin-bottom:8px;padding-left:20px;position:relative}
    li::before{content:'•';position:absolute;left:0;color:#58a6ff}
</style>";

echo "<h2>🧪 Diagnóstico FitPaisa: Nivel TESTING (v3.0.0)</h2>";

// 1. Verificación de Servidor
echo "<div class='card'>";
echo "<h3>1. Entorno del Servidor</h3><ul>";
echo "<li>Versión de PHP: <strong>" . PHP_VERSION . "</strong></li>";
echo "<li>Extensión 'mbstring': " . (extension_loaded('mbstring') ? "<span class='ok'>SÍ</span>" : "<span class='err'>NO</span>") . "</li>";
echo "<li>Extensión 'openssl': " . (extension_loaded('openssl') ? "<span class='ok'>SÍ</span>" : "<span class='err'>NO</span>") . "</li>";
echo "<li>Extensión 'pdo_pgsql': " . (extension_loaded('pdo_pgsql') ? "<span class='ok'>SÍ</span>" : "<span class='err'>NO</span>") . "</li>";
echo "</ul></div>";

// 2. Verificación de Variables de Entorno (sin revelar valores)
echo "<div class='card'>";
echo "<h3>2. Variables de Entorno (Visibilidad)</h3><ul>";
$vars = [
    'PGHOST'             => 'Host Principal (Pooler)',
    'PGUSER'             => 'Usuario BD',
    'PGDATABASE'         => 'Nombre de BD',
    'DB_PASSWORD_NUEVA'  => 'Contraseña (Manual/Correcta)',
    'PGPASSWORD'         => 'Contraseña (Vercel/Posible Error)',
    'JWT_SECRET'         => 'Llave de Seguridad JWT',
    'VERCEL_ENV'         => 'Entorno de Despliegue'
];

foreach ($vars as $key => $desc) {
    $val = getenv($key);
    $status = (!empty($val)) ? "<span class='ok'>CONFIGURADA</span>" : "<span class='err'>VACÍA</span>";
    echo "<li>$desc (<code>$key</code>): $status</li>";
}
echo "</ul></div>";

// 3. Intento de Conexión Real con Prioridad
echo "<div class='card'>";
echo "<h3>3. Prueba de Fuego: Conexión PDO (Kernel Centralizado)</h3>";

try {
    require_once __DIR__ . '/_db.php';
    
    $start = microtime(true);
    $pdo = fp_db();
    $end = microtime(true);
    $time = round(($end - $start) * 1000, 2);

    $info = fp_env_info();
    echo "<p>Entorno detectado: <strong>" . $info['env'] . "</strong></p>";
    echo "<p>Base de datos activa: <code>" . $info['database'] . "</code></p>";
    echo "<p class='ok'>✅ Conexión exitosa en $time ms.</p>";
    
    // Verificar tablas críticas
    $tables = ['users', 'profiles', 'subscriptions', 'workout_plans', 'food_catalog', 'rate_limits'];
    echo "<p>Estructura de Tablas:</p><ul>";
    foreach ($tables as $t) {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :t");
        $stmt->execute([':t' => $t]);
        if ($stmt->fetch()) {
            echo "<li>Tabla <code>$t</code>: <span class='ok'>EXISTE</span></li>";
        } else {
            echo "<li>Tabla <code>$t</code>: <span class='err'>NO ENCONTRADA</span></li>";
        }
    }
    echo "</ul>";

} catch (Exception $e) {
    echo "<p class='err'>❌ FALLO CRÍTICO DE CONEXIÓN:</p>";
    echo "<pre style='background:#2d1a1a;padding:10px;border:1px solid #ff7b72'>" . $e->getMessage() . "</pre>";
    
    echo "<h4>Posibles Causas:</h4><ul>
        <li>Variables de entorno no propagadas en Vercel.</li>
        <li>Nombre de BD incorrecto (actualmente forzando 'fitpaisa_testing' en previews).</li>
        <li>SSL Requirements: Neon requiere sslmode=require.</li>
    </ul>";
}
echo "</div>";

echo "<p style='text-align:center;color:var(--muted);font-size:12px'>FitPaisa Debug Tools 2026</p>";
