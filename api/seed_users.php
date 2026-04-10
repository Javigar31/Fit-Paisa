<?php
require_once __DIR__ . '/_db.php';
fp_cors();

$db = fp_db();

$users = [
    [
        'email' => 'garciajavierandres@hotmail.com',
        'pass'  => 'Javigar.admin1731',
        'name'  => 'Javier García (Admin)',
        'role'  => 'ADMIN'
    ],
    [
        'email' => 'javigar.1731@hotmail.com',
        'pass'  => 'Javigar.coach1731',
        'name'  => 'Javier García (Coach)',
        'role'  => 'COACH'
    ]
];

$results = [];

foreach ($users as $u) {
    try {
        $hash = password_hash($u['pass'], PASSWORD_BCRYPT, ['cost' => 12]);
        
        $stmt = $db->prepare("
            INSERT INTO users (email, password_hash, full_name, role, is_active, created_at)
            VALUES (:email, :hash, :name, CAST(:role AS user_role), TRUE, NOW())
            ON CONFLICT (email) DO UPDATE SET password_hash = :hash, role = CAST(:role AS user_role)
        ");
        
        $stmt->execute([
            ':email' => strtolower($u['email']),
            ':hash'  => $hash,
            ':name'  => $u['name'],
            ':role'  => $u['role']
        ]);
        
        $results[] = "Usuario {$u['email']} creado/actualizado como {$u['role']}.";
    } catch (Exception $e) {
        $results[] = "Error con {$u['email']}: " . $e->getMessage();
    }
}

fp_success(['status' => 'Seeding completed', 'results' => $results]);
