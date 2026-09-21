<?php
/* ============================================================
   MAILER — Envío de correos con PHPMailer (SMTP)
   Config en /config/config.php (LS_MAIL_*)
   ============================================================ */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../libs/PHPMailer/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Envía un correo HTML. Devuelve [ok(bool), error(string)].
 */
function lsSendMail($to, $subject, $htmlBody, $altBody = '') {
    if (!defined('LS_MAIL_ENABLED') || !LS_MAIL_ENABLED) {
        return [false, 'Envío de correo desactivado (LS_MAIL_ENABLED = false).'];
    }
    if (strpos(LS_MAIL_PASS, 'PON_AQUI') !== false || empty(LS_MAIL_PASS)) {
        return [false, 'Falta configurar la contraseña SMTP (App Password) en config/config.php.'];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = LS_MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = LS_MAIL_USER;
        $mail->Password   = LS_MAIL_PASS;
        $mail->Port       = intval(LS_MAIL_PORT);
        $mail->CharSet    = 'UTF-8';
        if (LS_MAIL_SECURE === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom(LS_MAIL_FROM, LS_MAIL_FROM_NAME);
        foreach ((array)$to as $dest) {
            if (filter_var($dest, FILTER_VALIDATE_EMAIL)) $mail->addAddress($dest);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = lsMailWrap($htmlBody);
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);

        $mail->send();
        return [true, ''];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}

/**
 * Envuelve el contenido en una plantilla HTML corporativa simple.
 */
function lsMailWrap($inner) {
    $wa = defined('LS_WHATSAPP') ? LS_WHATSAPP : '';
    return '<!DOCTYPE html><html><body style="margin:0;background:#0f0f1a;font-family:Arial,Helvetica,sans-serif;color:#e2e8f0">'
        . '<div style="max-width:560px;margin:0 auto;padding:24px">'
        . '<div style="background:linear-gradient(135deg,#6C3CE1,#E94560);border-radius:16px 16px 0 0;padding:22px 28px">'
        . '<h1 style="margin:0;font-size:20px;color:#fff">⚡ Comandix Licencias</h1></div>'
        . '<div style="background:#1A1A2E;border-radius:0 0 16px 16px;padding:28px;border:1px solid rgba(255,255,255,0.06);border-top:none">'
        . $inner
        . '<hr style="border:none;border-top:1px solid rgba(255,255,255,0.08);margin:24px 0">'
        . '<p style="font-size:12px;color:#94A3B8;margin:0">Comandix · POS para restaurantes'
        . ($wa ? ' · WhatsApp: +' . htmlspecialchars($wa) : '') . '</p>'
        . '</div></div></body></html>';
}

/* ------------------------------------------------------------
   Notificaciones de negocio (al administrador)
   ------------------------------------------------------------ */
function lsNotifyAdmin($subject, $htmlBody) {
    $to = defined('LS_NOTIFY_EMAIL') ? LS_NOTIFY_EMAIL : LS_MAIL_FROM;
    return lsSendMail($to, $subject, $htmlBody);
}

function lsRow($label, $value) {
    return '<tr><td style="padding:6px 0;color:#94A3B8;font-size:13px">' . htmlspecialchars($label)
        . '</td><td style="padding:6px 0;color:#fff;font-size:13px;font-weight:600;text-align:right">'
        . htmlspecialchars($value) . '</td></tr>';
}

function lsMailTable($rows) {
    return '<table style="width:100%;border-collapse:collapse;margin:12px 0">' . implode('', $rows) . '</table>';
}
