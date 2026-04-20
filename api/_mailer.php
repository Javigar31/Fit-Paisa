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
 * @return bool           True si se envió correctamente (202 Accepted), False si falló.
 */
function fp_mail(string $to, string $subject, string $html): bool
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
    <title>Recupera tu acceso a FitPaisa</title>
    <style>
        body { margin: 0; padding: 0; background-color: #080a0f; font-family: sans-serif; color: #f0f4f8; }
        .wrapper { width: 100%; background-color: #080a0f; padding: 40px 0; }
        .main { background-color: #0d1117; margin: 0 auto; width: 100%; max-width: 500px; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; overflow: hidden; }
        .header { background: #1a0d12; padding: 30px; text-align: center; border-bottom: 1px solid #ff3b3b; }
        .logo { font-size: 24px; font-weight: 900; color: #ff3b3b; text-decoration: none; }
        .content { padding: 40px 20px; text-align: center; }
        .title { font-size: 22px; color: #fff; margin-bottom: 15px; }
        .code-box { background: rgba(255, 59, 59, 0.1); border: 2px dashed #ff3b3b; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .code-text { font-size: 40px; font-weight: bold; color: #ff3b3b; letter-spacing: 8px; margin: 0; }
    </style>
</head>
<body>
    <div class="wrapper">
        <center>
            <table class="main">
                <tr><td class="header"><a href="#" class="logo">FITPAISA</a></td></tr>
                <tr>
                    <td class="content">
                        <h1 class="title">Código de verificación</h1>
                        <p>Has solicitado restablecer tu contraseña en FitPaisa.</p>
                        <div class="code-box"><h2 class="code-text">{{CODE}}</h2></div>
                        <p style="font-size: 12px; color: #71717a;">Este código expirará en 15 minutos.</p>
                    </td>
                </tr>
            </table>
        </center>
    </div>
</body>
</html>
HTML;

    return str_replace('{{CODE}}', $code, $template);
}
