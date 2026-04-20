<?php
/**
 * FitPaisa — Endpoint de Contacto
 *
 * Procesa mensajes del formulario de contacto, aplica seguridad (honeypot/rate-limit)
 * y envía notificaciones tanto al administrador como al usuario.
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 * @version  1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_mailer.php';

// Solo aceptar peticiones POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fp_error(405, 'Método no permitido.');
}

// Obtener y decodificar el cuerpo JSON
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$name    = trim($input['name'] ?? '');
$email   = trim($input['email'] ?? '');
$subject = trim($input['subject'] ?? 'Consulta desde FitPaisa');
$message = trim($input['message'] ?? '');
$honeypot = $input['_website'] ?? ''; // El campo trampa

// 1. Seguridad: Honeypot (Si está relleno, es un bot)
if (!empty($honeypot)) {
    // Respondemos con éxito pero ignoramos el envío para no dar pistas al bot
    fp_success(['message' => 'Mensaje recibido (bot filtered).']);
}

// 2. Validación básica
if (empty($name) || empty($email) || empty($message)) {
    fp_error(400, 'Nombre, email y mensaje son obligatorios.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fp_error(400, 'El formato del email no es válido.');
}

// 3. Seguridad: Rate Limit (3 mensajes por hora por IP)
fp_rate_limit('contact_form', 3, 3600);

try {
    // 4. Notificación al Administrador
    $adminEmail = 'garciajavierandres@hotmail.com';
    $adminBody = "<h2>Nuevo mensaje de contacto</h2>" .
                 "<p><strong>De:</strong> " . htmlspecialchars($name) . " (" . htmlspecialchars($email) . ")</p>" .
                 "<p><strong>Asunto:</strong> " . htmlspecialchars($subject) . "</p>" .
                 "<p><strong>Mensaje:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>";
    
    fp_mail($adminEmail, "FitPaisa Contact: " . $subject, $adminBody);

    // 5. Confirmación al Usuario (Newsletter Clean White)
    $userBody = fp_get_contact_confirmation_template($name, $message);
    fp_mail($email, "Hemos recibido tu mensaje — FitPaisa", $userBody);

    fp_success(['message' => '¡Mensaje enviado con éxito! Revisa tu bandeja de entrada.']);

} catch (Exception $e) {
    error_log("[FitPaisa][CONTACT] Error: " . $e->getMessage());
    fp_error(500, 'No pudimos procesar tu mensaje. Inténtalo más tarde.');
}
