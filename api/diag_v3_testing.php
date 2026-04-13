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
echo "<h3>3. Prueba de Fuego: Conexión PDO</h3>";

try {
    $host = getenv('PGHOST') ?: getenv('POSTGRES_HOST');
    $user = getenv('PGUSER') ?: getenv('POSTGRES_USER');
    $db   = getenv('PGDATABASE') ?: getenv('POSTGRES_DATABASE');
    
    // REGLA DE ORO de ayer: Priorizar DB_PASSWORD_NUEVA
    $pass = getenv('DB_PASSWORD_NUEVA') ?: getenv('PGPASSWORD');

    echo "<p>Intentando conectar a <code>$host</code> con usuario <code>$user</code>...</p>";
    
    $start = microtime(true);
    $dsn = "pgsql:host=$host;port=5432;dbname=$db;sslmode=require";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    $end = microtime(true);
    $time = round(($end - $start) * 1000, 2);

    echo "<p class='ok'>✅ Conexión exitosa en $time ms.</p>";
    
    // Verificar tablas críticas
    $tables = ['users', 'profiles', 'workout_plans', 'food_entries'];
    echo "<p>Estructura:</p><ul>";
    foreach ($tables as $t) {
        $q = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = '$t'");
        if ($q && $q->fetch()) {
            echo "<li>Tabla <code>$t</code>: <span class='ok'>EXISTE</span></li>";
        } else {
            echo "<li>Tabla <code>$t</code>: <span class='err'>NOT FOUND</span></li>";
        }
    }
    echo "</ul>";

} catch (PDOException $e) {
    echo "<p class='err'>❌ FALLO DE CONEXIÓN:</p>";
    echo "<pre style='background:#2d1a1a;padding:10px;border:1px solid #ff7b72'>" . $e->getMessage() . "</pre>";
    
    if (strpos($e->getMessage(), 'authentication failed') !== false) {
        echo "<p class='warn'>💡 Sugerencia: El error es de contraseña. Verifica que DB_PASSWORD_NUEVA sea correcta.</p>";
    }
}
echo "</div>";

echo "<p style='text-align:center;color:var(--muted);font-size:12px'>FitPaisa Debug Tools 2026</p>";
