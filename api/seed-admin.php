<?php
/**
 * FitPaisa — Script de Seed del Usuario Administrador
 *
 * Crea el usuario administrador inicial de la plataforma.
 * Protegido por token secreto. Ejecutar UNA sola vez.
 *
 * Uso: GET /api/seed-admin.php?token=SETUP_TOKEN&email=tu@email.com&password=TuPass123!&name=Tu+Nombre
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

header('Content-Type: application/json; charset=utf-8');

/* ── Protección por token ── */
$setupToken    = getenv('SETUP_TOKEN') ?: 'FITPAISA_SETUP_2026';
$providedToken = $_GET['token'] ?? '';

if (!hash_equals($setupToken, $providedToken)) {
    fp_error(403, 'Token inválido. Acceso denegado.');
}

/* ── Parámetros del admin ── */
$email    = strtolower(fp_sanitize($_GET['email']    ?? '', 150));
$password = $_GET['password'] ?? '';
$name     = fp_sanitize($_GET['name'] ?? 'Administrador FitPaisa', 200);
$role     = fp_sanitize($_GET['role'] ?? 'ADMIN', 10);   /* ADMIN | COACH | USER */

/* ── Validaciones básicas ── */
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fp_error(400, 'Email inválido. Usa ?email=tu@correo.com en la URL.');
}
if (strlen($password) < 8) {
    fp_error(400, 'La contraseña debe tener mínimo 8 caracteres. Usa ?password=TuPass123!');
}
if (!in_array($role, ['ADMIN', 'COACH', 'USER'], true)) {
    fp_error(400, 'Rol inválido. Usa ADMIN, COACH o USER.');
}

/* ── Verificar si ya existe ── */
$exists = fp_query(
    'SELECT user_id, role FROM users WHERE email = :email',
    [':email' => $email]
)->fetch();

if ($exists) {
    /* Si existe, actualizar el rol Y la contraseña */
    $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    fp_query(
        'UPDATE users SET role = :role, password_hash = :hash WHERE email = :email',
        [':role' => $role, ':hash' => $newHash, ':email' => $email]
    );

    fp_success([
        'message'  => "Usuario actualizado: rol={$role} y contraseña renovada.",
        'user_id'  => $exists['user_id'],
        'email'    => $email,
        'role'     => $role,
        'action'   => 'updated',
    ]);
}

/* ── Crear nuevo admin ── */
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = fp_query(
    "INSERT INTO users (email, password_hash, full_name, role, is_active, created_at)
     VALUES (:email, :hash, :name, :role, TRUE, NOW())
     RETURNING user_id",
    [
        ':email' => $email,
        ':hash'  => $hash,
        ':name'  => $name,
        ':role'  => $role,
    ]
);

$userId = $stmt->fetchColumn();

fp_success([
    'message' => "Usuario {$role} creado correctamente. ¡Ya puedes iniciar sesión!",
    'user_id' => $userId,
    'email'   => $email,
    'name'    => $name,
    'role'    => $role,
    'action'  => 'created',
    'next'    => 'Inicia sesión en el formulario del index.html con estas credenciales.',
]);
