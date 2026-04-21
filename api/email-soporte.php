<?php
/**
 * FitPaisa — Template de Email de Confirmación de Soporte
 *
 * Genera el HTML del correo que se envía al usuario cuando envía
 * un ticket desde el panel de cliente.
 *
 * Uso: fp_email_soporte($nombre, $correo, $mensaje, $ticketId)
 *
 * @package  FitPaisa\Api
 * @version  1.0.0
 */

declare(strict_types=1);

/**
 * Genera el HTML del email de confirmación de soporte.
 *
 * @param string $nombre    Nombre del usuario.
 * @param string $correo    Correo del usuario.
 * @param string $mensaje   Mensaje enviado (se muestra truncado).
 * @param string $ticketId  ID único del ticket (p.ej. FP-1713701234).
 * @return string           HTML listo para incrustar en el correo.
 */
function fp_email_soporte(string $nombre, string $correo, string $mensaje, string $ticketId): string
{
    $mensajePreview = mb_strlen($mensaje) > 160
        ? mb_substr($mensaje, 0, 160) . '…'
        : $mensaje;

    $nombreHtml    = htmlspecialchars($nombre,         ENT_QUOTES, 'UTF-8');
    $correoHtml    = htmlspecialchars($correo,         ENT_QUOTES, 'UTF-8');
    $mensajeHtml   = htmlspecialchars($mensajePreview, ENT_QUOTES, 'UTF-8');
    $ticketHtml    = htmlspecialchars($ticketId,       ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Soporte FitPaisa</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700&family=Inter:wght@400;500&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #080a0f; font-family: 'Inter', sans-serif; color: #e2e2e9; }
    .wrapper { max-width: 600px; margin: 0 auto; padding: 32px 16px; }

    /* ── Header ── */
    .header { text-align: center; padding: 32px 0 24px; border-bottom: 1px solid rgba(255,59,59,0.3); }
    .header-logo { font-family: 'Space Grotesk', sans-serif; font-size: 28px; font-weight: 700; letter-spacing: 4px; color: #ff3b3b; }
    .header-tagline { font-size: 11px; letter-spacing: 3px; text-transform: uppercase; color: #6b7280; margin-top: 4px; }

    /* ── Hero ── */
    .hero { padding: 40px 32px 32px; text-align: center; }
    .hero-icon { width: 56px; height: 56px; background: rgba(255,59,59,0.12); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; border: 1px solid rgba(255,59,59,0.25); }
    .hero-title { font-family: 'Space Grotesk', sans-serif; font-size: 26px; font-weight: 700; color: #ffffff; line-height: 1.25; margin-bottom: 12px; }
    .hero-subtitle { font-size: 15px; color: #9ca3af; line-height: 1.6; }

    /* ── Ticket Card ── */
    .ticket-card { margin: 8px 24px 32px; background: rgba(30,31,37,0.85); border: 1px solid rgba(255,59,59,0.15); border-radius: 12px; overflow: hidden; }
    .ticket-header { background: rgba(255,59,59,0.08); padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,59,59,0.1); }
    .ticket-header-label { font-family: 'Space Grotesk', sans-serif; font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: #6b7280; }
    .ticket-id { background: rgba(255,59,59,0.15); border: 1px solid rgba(255,59,59,0.3); border-radius: 6px; padding: 4px 10px; font-family: 'Space Grotesk', sans-serif; font-size: 12px; font-weight: 600; color: #ff6b6b; }
    .ticket-body { padding: 20px; }
    .ticket-row { display: flex; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.04); }
    .ticket-row:last-child { border-bottom: none; }
    .ticket-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: #6b7280; width: 80px; flex-shrink: 0; padding-top: 2px; }
    .ticket-value { font-size: 14px; color: #e2e2e9; line-height: 1.5; }
    .ticket-message { background: rgba(255,255,255,0.03); border-radius: 8px; padding: 12px; font-size: 13px; color: #9ca3af; line-height: 1.6; font-style: italic; }

    /* ── CTA ── */
    .cta-wrap { padding: 0 24px 32px; }
    .cta-btn { display: block; width: 100%; padding: 16px; background: linear-gradient(135deg, #ff3b3b 0%, #c00018 100%); color: #ffffff; font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; text-align: center; text-decoration: none; border-radius: 8px; letter-spacing: 1px; text-transform: uppercase; }

    /* ── Footer ── */
    .footer { padding: 24px 32px 40px; text-align: center; border-top: 1px solid rgba(255,255,255,0.06); }
    .footer-text { font-size: 12px; color: #4b5563; line-height: 1.6; }
    .footer-copy { font-size: 11px; color: #374151; margin-top: 12px; letter-spacing: 1px; text-transform: uppercase; }
  </style>
</head>
<body>
  <div class="wrapper">

    <!-- Header -->
    <div class="header">
      <div class="header-logo">FITPAISA</div>
      <div class="header-tagline">Kinetic Performance Systems</div>
    </div>

    <!-- Hero -->
    <div class="hero">
      <div class="hero-icon">
        <!-- Checkmark SVG -->
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ff3b3b" stroke-width="2.5">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
      </div>
      <div class="hero-title">Hemos recibido<br>tu mensaje ✓</div>
      <p class="hero-subtitle">Nuestro equipo de soporte te responderá<br>en las próximas <strong style="color:#e2e2e9">24 horas</strong>.</p>
    </div>

    <!-- Ticket Card -->
    <div class="ticket-card">
      <div class="ticket-header">
        <span class="ticket-header-label">Detalles del ticket</span>
        <span class="ticket-id">{$ticketHtml}</span>
      </div>
      <div class="ticket-body">
        <div class="ticket-row">
          <span class="ticket-label">Nombre</span>
          <span class="ticket-value">{$nombreHtml}</span>
        </div>
        <div class="ticket-row">
          <span class="ticket-label">Correo</span>
          <span class="ticket-value">{$correoHtml}</span>
        </div>
        <div class="ticket-row">
          <span class="ticket-label">Categoría</span>
          <span class="ticket-value">Soporte Cliente FitPaisa</span>
        </div>
        <div class="ticket-row">
          <span class="ticket-label">Mensaje</span>
          <div class="ticket-message">"{$mensajeHtml}"</div>
        </div>
      </div>
    </div>

    <!-- CTA -->
    <div class="cta-wrap">
      <a href="https://fit-paisa.vercel.app" class="cta-btn">Ir a FitPaisa →</a>
    </div>

    <!-- Footer -->
    <div class="footer">
      <p class="footer-text">
        Este correo fue enviado automáticamente. Por favor, no respondas a este mensaje.<br>
        Si no enviaste esta solicitud, puedes ignorar este correo de manera segura.
      </p>
      <div class="footer-copy">© 2025 FITPAISA · All rights reserved</div>
    </div>

  </div>
</body>
</html>
HTML;
}
