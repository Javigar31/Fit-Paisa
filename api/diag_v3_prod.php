<?php
/**
 * FitPaisa — Diagnóstico Avanzado v3 (PRODUCCIÓN)
 * Verifica entorno master con total seguridad.
 */

declare(strict_types=1);

// Protección básica: solo ejecutable si se conoce el parámetro secreto (opcional)
// if (($_GET['key'] ?? '') !== 'tu_llave_secreta') { die('Acceso denegado'); }

header('Content-Type: text/html; charset=utf-8');
echo "<style>
    body{background:#0d1117;color:#c9d1d9;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;padding:30px;line-height:1.6}
    .card{background:#161b22;border:1px solid #30363d;padding:25px;border-radius:10px;margin-bottom:20px}
    .ok{color:#3fb950;font-weight:bold}
    .err{color:#f85149;font-weight:bold}
    h2{color:#58a6ff;border-bottom:1px solid #30363d;padding-bottom:10px}
    code{background:rgba(110,118,129,0.4);padding:2px 5px;border-radius:6px;font-family:ui-monospace,SFMono-Regular,SF Mono,Menlo,Consolas,Liberation Mono,monospace}
</style>";

echo "<h2>🚀 Diagnóstico FitPaisa: Nivel PRODUCCIÓN (v3.0.0)</h2>";

// 1. Servidor
echo "<div class='card'>";
echo "<h3>1. Entorno de Producción</h3>";
echo "<ul><li>PHP Version: " . PHP_VERSION . "</li>";
echo "<li>Entorno Detectado: <code>" . (getenv('VERCEL_ENV') ?: 'local/unknown') . "</code></li></ul>";
echo "</div>";

// 2. Variables Críticas (Visibilidad)
echo "<div class='card'>";
echo "<h3>2. Estado de Variables PROD</h3><ul>";
$vars = [
    'PGHOST_PROD'        => 'Host de Producción',
    'PGUSER_PROD'        => 'Usuario PROD',
    'PGDATABASE_PROD'    => 'Nombre DB PROD',
    'DB_PASSWORD_NUEVA'  => 'Contraseña Manual (Prioritaria)',
    'JWT_SECRET'         => 'JWT Configurado'
];

foreach ($vars as $key => $desc) {
    if (strpos($key, 'PASSWORD') !== false || strpos($key, 'SECRET') !== false) {
        $status = !empty(getenv($key)) ? "<span class='ok'>PRESENTE (Oculta)</span>" : "<span class='err'>FALTA</span>";
    } else {
        $status = "<code>" . (getenv($key) ?: "<span class='err'>VACÍO</span>") . "</code>";
    }
    echo "<li>$desc: $status</li>";
}
echo "</ul></div>";

// 3. Conexión PDO Pura
echo "<div class='card'>";
echo "<h3>3. Conexión Real a Producción</h3>";

try {
    // Prioridad PROD
    $host = getenv('PGHOST_PROD') ?: getenv('PGHOST');
    $user = getenv('PGUSER_PROD') ?: getenv('PGUSER');
    $db   = getenv('PGDATABASE_PROD') ?: 'neondb';
    $pass = getenv('DB_PASSWORD_NUEVA'); // En prod somos estrictos con esta

    if (!$pass) throw new Exception("Falta la variable DB_PASSWORD_NUEVA");

    $start = microtime(true);
    $dsn = "pgsql:host=$host;port=5432;dbname=$db;sslmode=require";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $end = microtime(true);
    
    echo "<p class='ok'>✅ Conexión con Producción Exitosa en " . round(($end - $start) * 1000, 1) . "ms</p>";
    echo "<p>Base de datos: <code>$db</code></p>";

} catch (Exception $e) {
    echo "<p class='err'>❌ FALLO EN PRODUCCIÓN:</p>";
    echo "<pre style='background:#1b1b1b;color:#ff7b72;padding:15px;border-radius:8px'>" . $e->getMessage() . "</pre>";
}
echo "</div>";
