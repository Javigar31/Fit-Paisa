<?php
// register.php
require_once 'config.php';
require_once 'jwt_helper.php';

// Solo aceptar peticiones POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
    exit;
}

// Leer JSON recibido
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['name']) || !isset($data['email']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing fields"]);
    exit;
}

$name = trim($data['name']);
$email = trim($data['email']);
$password = $data['password'];

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Password must be at least 6 characters"]);
    exit;
}

$db = getDB();

// Comprobar si el email ya existe
$stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(["status" => "error", "message" => "Email already registered"]);
    exit;
}

// Crear usuario
$hashed_password = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$role = isset($data['role']) && $data['role'] === 'COACH' ? 'COACH' : 'CLIENT';

$stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)");
$inserted = $stmt->execute([
    'name' => $name,
    'email' => $email,
    'password_hash' => $hashed_password,
    'role' => $role
]);

if ($inserted) {
    $userId = $db->lastInsertId();
    
    // Auto-login (emitir JWT)
    $payload = [
        'id' => $userId,
        'email' => $email,
        'role' => $role
    ];
    $token = create_jwt($payload);
    
    http_response_code(201);
    echo json_encode([
        "status" => "success", 
        "message" => "User registered successfully",
        "token" => $token,
        "user" => [
            "id" => $userId,
            "name" => $name,
            "email" => $email,
            "role" => $role
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to create user"]);
}
?>
