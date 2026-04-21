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
        'Reply-To: ' . $senderEmail
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
    <title>Bienvenido a FitPaisa</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f9fafb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1f2937; }
        .container { width: 100%; max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .card { background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 40px; text-align: center; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
        .logo { color: #ff3b3b; font-size: 28px; font-weight: 800; text-decoration: none; display: block; margin-bottom: 32px; letter-spacing: 1px; }
        .title { font-size: 24px; font-weight: 700; color: #111827; margin-bottom: 16px; }
        .text { font-size: 16px; color: #4b5563; line-height: 1.6; margin-bottom: 32px; }
        .btn { background-color: #ff3b3b; color: #ffffff !important; padding: 16px 32px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 14px; display: inline-block; text-transform: uppercase; letter-spacing: 1px; }
        .footer { font-size: 12px; color: #9ca3af; margin-top: 32px; text-align: center; }
        .footer a { color: #ff3b3b; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <a href="https://fit-paisa.vercel.app" class="logo">FITPAISA</a>
            <h1 class="title">Te damos la bienvenida</h1>
            <p class="text">Hola <strong>{{NAME}}</strong>,<br><br>Tu cuenta en FitPaisa ha sido creada con éxito. Ya puedes acceder al ecosistema para gestionar tus objetivos y consultar tus planes.</p>
            <div style="margin-bottom: 32px;">
                <a href="https://fit-paisa.vercel.app" class="btn">EMPEZAR AHORA</a>
            </div>
            <p class="text" style="font-size: 14px; margin-bottom: 0;">Gracias por formar parte de FitPaisa.</p>
        </div>
        <div class="footer">
            &copy; 2026 FitPaisa. Diseñado para el Alto Rendimiento.<br>
            Puedes gestionar tu cuenta en <a href="https://fit-paisa.vercel.app">fit-paisa.vercel.app</a>
        </div>
    </div>
</body>
</html>
HTML;

    return str_replace('{{NAME}}', $name, $template);
}

/**
 * Genera el cuerpo del email de confirmación de contacto.
 * Estilo Clean White para máxima entregabilidad.
 *
 * @param string $name    Nombre del remitente.
 * @param string $message Mensaje original (fragmento).
 * @return string         HTML completo.
 */
function fp_get_contact_confirmation_template(string $name, string $message): string
{
    $name = htmlspecialchars($name);
    $excerpt = htmlspecialchars(mb_strimwidth($message, 0, 100, "..."));
    
    // Stitch Tokens: Kinetic Void
    // Accent: #ff544e (Primary Container)
    // Dark: #111319 (Background)
    // Surface: #ffffff
    
    $template = <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensaje Transmitido</title>
    <style>
        /* Stitch 'Kinetic Void' Strategy */
        body { margin: 0; padding: 0; background-color: #111319; font-family: 'Space Grotesk', 'Inter', -apple-system, sans-serif; color: #ffffff; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #111319; }
        .container { width: 100%; max-width: 600px; margin: 0 auto; }
        .content { padding: 60px 24px; }
        .card { background-color: #1e1f25; border-radius: 4px; border: 1px solid rgba(255, 84, 78, 0.2); overflow: hidden; position: relative; }
        .accent-line { height: 2px; background: linear-gradient(90deg, #ff544e, #c00018); width: 100%; }
        .card-body { padding: 48px; }
        .logo { font-size: 20px; font-weight: 900; letter-spacing: 3px; color: #ffffff; text-decoration: none; display: block; margin-bottom: 50px; }
        .logo span { color: #ff544e; }
        .title { font-size: 32px; font-weight: 800; color: #ffffff; margin: 0 0 20px 0; line-height: 1.1; text-transform: uppercase; letter-spacing: -1px; }
        .subtitle { font-size: 16px; color: #e2e2e9; line-height: 1.7; margin: 0 0 40px 0; font-weight: 300; }
        .quote-box { background-color: rgba(255, 255, 255, 0.03); border-left: 2px solid #ff544e; padding: 24px; border-radius: 0 4px 4px 0; margin-bottom: 40px; }
        .quote-text { font-style: italic; color: #ad8884; font-size: 14px; line-height: 1.6; margin: 0; }
        .footer { padding: 40px 24px; text-align: left; font-size: 11px; color: #5d3f3c; text-transform: uppercase; letter-spacing: 2px; }
        .footer a { color: #ff544e; text-decoration: none; font-weight: 700; margin-right: 15px; }
        
        @media only screen and (max-width: 600px) {
            .content { padding: 30px 16px; }
            .card-body { padding: 30px; }
            .title { font-size: 26px; }
        }
    </style>
</head>
<body>
    <table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center">
                <div class="container">
                    <div class="content">
                        <div class="card">
                            <div class="accent-line"></div>
                            <div class="card-body">
                                <a href="https://fit-paisa.vercel.app" class="logo">FIT<span>PAISA</span></a>
                                <h1 class="title">Mensaje<br>Transmitido</h1>
                                <p class="subtitle">Hemos recibido tu transmisión en el ecosistema. Nuestro equipo de alto rendimiento está revisando los datos y se pondrá en contacto contigo en breve.</p>
                                
                                <div style="font-size:10px; text-transform:uppercase; letter-spacing:3px; color:#ff544e; margin-bottom:12px; font-weight:700;">Resumen de Datos</div>
                                <div class="quote-box">
                                    <p class="quote-text">"{{EXCERPT}}"</p>
                                </div>
                                
                                <p style="font-size:13px; color:#ad8884; margin:0;">No es necesario responder. El sistema está en proceso.</p>
                            </div>
                        </div>
                        
                        <div class="footer">
                            <strong>Kinetic Void &copy; 2026</strong><br><br>
                            <a href="https://fit-paisa.vercel.app">Ecosistema</a>
                            <a href="https://fit-paisa.vercel.app/dashboard">Transmisiones</a>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    $template = str_replace(['{{NAME}}', '{{EXCERPT}}'], [$name, $excerpt], $template);
    return $template;
}
