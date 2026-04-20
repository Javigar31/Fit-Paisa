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
                'value' => "Recupera tu acceso a FitPaisa. Tu código de verificación es: $code\n\nEste código expirará en 15 minutos."
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
