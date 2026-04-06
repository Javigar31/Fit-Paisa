<?php
// get_dashboard.php
require_once 'config.php';
require_once 'jwt_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
    exit;
}

// 1. Extraer y validar el Token JWT
$token = get_bearer_token();
if (!$token) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Authorization header missing or invalid"]);
    exit;
}

$decoded = validate_jwt($token);
if (!$decoded) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Token is invalid or has expired"]);
    exit;
}

// 2. Extraer datos del usuario validado
$userId = $decoded['id'];
$userRole = $decoded['role'];

$db = getDB();

// 3. Preparar los datos del dashboard según el rol
$dashboardData = [
    "status" => "success",
    "user" => $decoded,
    "data" => []
];

if ($userRole === 'CLIENT') {
    // Ejemplo de consultas para un cliente
    // A. Obtener sus ingestas recientes
    $stmtMeals = $db->prepare("SELECT SUM(calories) as total_cals, SUM(protein) as total_protein, SUM(carbs) as total_carbs, SUM(fats) as total_fats FROM meals WHERE user_id = :uid AND DATE(logged_at) = CURDATE()");
    $stmtMeals->execute(['uid' => $userId]);
    $macros = $stmtMeals->fetch();

    $dashboardData['data']['today_macros'] = [
        "calories" => $macros['total_cals'] ?? 0,
        "protein" => $macros['total_protein'] ?? 0,
        "carbs" => $macros['total_carbs'] ?? 0,
        "fats" => $macros['total_fats'] ?? 0
    ];
    
    // B. Obtener Perfil Nutricional (Objetivos)
    $stmtProfile = $db->prepare("SELECT * FROM profiles WHERE user_id = :uid");
    $stmtProfile->execute(['uid' => $userId]);
    $dashboardData['data']['profile'] = $stmtProfile->fetch() ?: null;
    
} elseif ($userRole === 'COACH') {
    // Dashboard para Entrenador
    // Mostrar clientes asignados (solo ejemplo conceptual)
    $dashboardData['data']['message'] = "Bienvenido Entrenador. Aquí verás los progresos de tus alumnos.";
} elseif ($userRole === 'ADMIN') {
    // Dashboard Admin
    $dashboardData['data']['message'] = "Panel de control general de métricas.";
}

echo json_encode($dashboardData);
?>
