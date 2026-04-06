<?php
// config.php
// Configuración básica de la base de datos para FitPaisa

define('DB_HOST', 'localhost');
define('DB_NAME', 'fitpaisa');
define('DB_USER', 'root'); // Cambiar por el usuario real
define('DB_PASS', '');     // Cambiar por la contraseña real

function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $db = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // En producción, es mejor devolver un error genérico (HTTP 500)
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Database connection failed"]);
            exit;
        }
    }
    return $db;
}

// Configuración general para que toda la API devuelva JSON
header('Content-Type: application/json');
// Permitir CORS (útil si el frontend está en otro puerto durante desarrollo)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Preflight response
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
?>
