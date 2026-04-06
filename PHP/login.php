<?php
// login.php
require_once 'config.php';
require_once 'jwt_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['email']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Email and password are required"]);
    exit;
}

$email = trim($data['email']);
$password = $data['password'];

$db = getDB();

$stmt = $db->prepare("SELECT id, name, email, password_hash, role, premium FROM users WHERE email = :email");
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

// Verificar usuario y contraseña
if ($user && password_verify($password, $user['password_hash'])) {
    // Éxito: generar JWT
    $payload = [
        'id' => $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'premium' => (bool)$user['premium']
    ];
    
    $token = create_jwt($payload);
    
    echo json_encode([
        "status" => "success",
        "message" => "Login successful",
        "token" => $token,
        "user" => [
            "id" => $user['id'],
            "name" => $user['name'],
            "email" => $user['email'],
            "role" => $user['role'],
            "premium" => (bool)$user['premium']
        ]
    ]);
} else {
    // Fallo de credenciales
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Invalid email or password"]);
}
?>
