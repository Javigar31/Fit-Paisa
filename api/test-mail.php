<?php
/**
 * FitPaisa — Script de prueba de Mail (Gmail SMTP)
 * 
 * Este script verifica que la configuración de PHPMailer y Gmail SMTP 
 * funcione correctamente desde el servidor.
 */

declare(strict_types=1);
require_once __DIR__ . '/_mailer.php';

header('Content-Type: application/json; charset=utf-8');

// Solo permitir si hay un parámetro de seguridad o mediante token (opcional)
$to = $_GET['to'] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Proporciona un email válido en el parámetro ?to=']);
    exit;
}

$subject = "Prueba de Conexión FitPaisa - " . date('H:i:s');
$code = (string)rand(100000, 999999);
$html = fp_get_recovery_template($code);

$success = fp_mail($to, $subject, $html, $code);

if ($success) {
    echo json_encode([
        'success' => true, 
        'message' => "Correo enviado con éxito a $to. Por favor, revisa tu bandeja de entrada y SPAM.",
        'details' => [
            'sender' => getenv('GMAIL_USER') ?: 'fit.paisa.app@gmail.com',
            'method' => 'Gmail SMTP (PHPMailer)'
        ]
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Error al enviar el correo. Revisa los logs del servidor para más detalles.',
        'error_info' => 'Verifica que GMAIL_APP_PASS sea correcta y que la cuenta tenga 2FA activo.'
    ]);
}
