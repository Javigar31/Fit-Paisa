<?php
/**
 * FitPaisa — Motor de Envío de Emails (SendGrid API)
 *
 * Utiliza cURL para enviar correos electrónicos a través de la API v3 de SendGrid.
 * Optimizado para Single Sender Verification en entornos sin dominio propio.
 *
 * @package  FitPaisa\Api
 * @version  1.1.0
 */

declare(strict_types=1);

/**
 * Envía un correo electrónico usando la API de SendGrid.
 *
 * @param string $to      Dirección de destino.
 * @param string $subject Asunto del correo.
 * @param string $html    Cuerpo del mensaje en HTML.
 * @param string $code    Código (para la versión en texto plano).
 * @return bool           True si se envió correctamente (202 Accepted), False si falló.
 */
function fp_mail(string $to, string $subject, string $html, string $code): bool
{
    $apiKey = getenv('SENDGRID_API_KEY');
    $senderEmail = getenv('SENDGRID_SENDER_EMAIL'); // Email verificado en SendGrid
    
    if (!$apiKey || !$senderEmail) {
        error_log("[FitPaisa][MAILER] Error: SENDGRID_API_KEY o SENDGRID_SENDER_EMAIL no configuradas.");
        return false;
    }

    $payload = [
        'personalizations' => [
            [
                'to' => [['email' => $to]]
            ]
        ],
        'from' => [
            'email' => $senderEmail,
            'name'  => 'FitPaisa'
        ],
        'subject' => $subject,
        'content' => [
            [
                'type'  => 'text/plain',
                'value' => $code ? "Tu código de verificación es: $code" : "Bienvenido a FitPaisa. Estamos emocionados de tenerte con nosotros."
            ],
            [
                'type'  => 'text/html',
                'value' => $html
            ]
        ]
    ];

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("[FitPaisa][MAILER] Fallo cURL: " . $curlError);
        return false;
    }

    // SendGrid devuelve 202 si ha aceptado el correo para procesarlo
    if ($httpCode === 202) {
        return true;
    }

    error_log("[FitPaisa][MAILER] Error API SendGrid (HTTP $httpCode): " . $response);
    return false;
}

/**
 * Genera el cuerpo del email de recuperación a partir de la plantilla Antigravity.
 *
 * @param string $code Código de 6 dígitos.
 * @return string      HTML completo.
 */
function fp_get_recovery_template(string $code): string
{
    $template = <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Código de Verificación - FitPaisa</title>
    <style>
        body { margin: 0; padding: 0; background-color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1f2937; }
        .container { width: 100%; max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .card { background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 32px; text-align: center; }
        .logo { color: #dc2626; font-size: 24px; font-weight: bold; text-decoration: none; display: block; margin-bottom: 24px; }
        .title { font-size: 20px; font-weight: 600; color: #111827; margin-bottom: 16px; }
        .text { font-size: 16px; color: #4b5563; line-height: 1.5; margin-bottom: 24px; }
        .code { font-size: 36px; font-weight: bold; color: #dc2626; letter-spacing: 4px; padding: 16px; background-color: #fef2f2; border-radius: 6px; display: inline-block; }
        .footer { font-size: 12px; color: #9ca3af; margin-top: 32px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <a href="#" class="logo">FitPaisa</a>
            <h1 class="title">Verifica tu identidad</h1>
            <p class="text">Has solicitado restablecer tu contraseña. Utiliza el siguiente código para completar el proceso:</p>
            <div class="code">{{CODE}}</div>
            <p class="text" style="font-size: 14px; margin-top: 24px;">Por seguridad, este código expirará en 15 minutos.</p>
        </div>
        <div class="footer">
            Si no has solicitado este cambio, puedes ignorar este mensaje de forma segura.
        </div>
    </div>
</body>
</html>
HTML;

    return str_replace('{{CODE}}', $code, $template);
}

/**
 * Genera el cuerpo del email de bienvenida (Newsletter Premium).
 * Basado en el concepto "Kinetic Void" de Stitch (Antigravity).
 *
 * @param string $name Nombre del usuario.
 * @return string      HTML completo.
 */
function fp_get_welcome_template(string $name): string
{
    $name = htmlspecialchars($name);
    $template = <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;700&display=swap');
        body { margin: 0; padding: 0; background-color: #080a0f; font-family: 'Inter', Arial, sans-serif; color: #e2e2e9; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #080a0f; padding-bottom: 40px; }
        .main { background-color: #080a0f; margin: 0 auto; width: 100%; max-width: 600px; border-spacing: 0; color: #e2e2e9; }
        .header { padding: 40px 20px; text-align: center; }
        .logo { color: #ff3b3b; font-family: 'Bebas Neue', sans-serif; font-size: 42px; text-decoration: none; letter-spacing: 2px; }
        .hero { background-color: #111319; border: 1px solid #33353a; border-radius: 16px; margin: 0 20px; padding: 40px 30px; text-align: center; }
        .title { font-family: 'Bebas Neue', sans-serif; font-size: 48px; color: #ffffff; line-height: 1; margin: 0 0 20px 0; letter-spacing: 1px; }
        .text { font-size: 16px; line-height: 1.6; color: #9ca3af; margin-bottom: 30px; }
        .btn-container { margin-bottom: 20px; }
        .btn { background-color: #ff3b3b; color: #080a0f !important; padding: 16px 32px; border-radius: 8px; text-decoration: none; font-weight: 800; font-size: 14px; display: inline-block; text-transform: uppercase; letter-spacing: 1px; }
        .secondary-btn { background-color: transparent; color: #ff3b3b !important; border: 1px solid #ff3b3b; margin-left: 10px; }
        .footer { padding: 40px 20px; text-align: center; font-size: 12px; color: #4b5563; }
        .footer a { color: #ff3b3b; text-decoration: none; }
    </style>
</head>
<body>
    <center class="wrapper">
        <table class="main" width="100%">
            <tr>
                <td class="header">
                    <a href="https://fit-paisa.vercel.app" class="logo">FITPAISA</a>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="hero">
                        <h1 class="title">BIENVENIDO A LA ÉLITE</h1>
                        <p class="text">Hola {{NAME}},<br><br>Has dado el primer paso hacia tu mejor versión. En FitPaisa no solo entrenamos, optimizamos. Estamos aquí para proporcionarte las herramientas de precisión que necesitas para dominar tus objetivos.</p>
                        <div class="btn-container">
                            <a href="https://fit-paisa.vercel.app" class="btn">EMPEZAR ENTRENAMIENTO</a>
                        </div>
                        <p style="font-size: 13px; color: #6b7280;">Prepárate para experimentar el rendimiento definitivo.</p>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="footer">
                    &copy; 2026 FitPaisa. Diseñado para el Alto Rendimiento.<br>
                    <a href="https://fit-paisa.vercel.app">Web App</a> &bull; <a href="#">Instagram</a> &bull; <a href="#">Soporte</a>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;

    $template = str_replace('{{NAME}}', $name, $template);
    return $template;
}
