<?php
/* ============================================================
   enviar.php — Procesa el formulario de contacto (AJAX)
   Valida + sanitiza los campos y envía el correo con PHPMailer.
   Responde SIEMPRE en JSON para consumo con fetch().
   ============================================================ */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/api/mailer.php';

function salir($ok, $mensaje) {
    echo json_encode(['exito' => $ok, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    salir(false, 'Método no permitido.');
}

// Aceptar JSON o form-urlencoded
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ctype, 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $data = $_POST;
}

// Honeypot anti-spam (campo oculto 'website' debe venir vacío)
if (!empty($data['website'])) {
    salir(true, 'Gracias por tu mensaje.'); // silencioso para bots
}

// Sanitización
$nombre  = trim(strip_tags($data['nombre']  ?? ''));
$email   = trim($data['email']   ?? '');
$tel     = trim(strip_tags($data['telefono'] ?? ''));
$asunto  = trim(strip_tags($data['asunto']   ?? 'Contacto desde la web'));
$mensaje = trim(strip_tags($data['mensaje']  ?? ''));

// Validación
$errores = [];
if (mb_strlen($nombre) < 2)  $errores[] = 'Escribe tu nombre.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'El correo no es válido.';
if (mb_strlen($mensaje) < 10) $errores[] = 'El mensaje es muy corto.';
if (mb_strlen($mensaje) > 4000) $errores[] = 'El mensaje es demasiado largo.';
if ($errores) {
    http_response_code(422);
    salir(false, implode(' ', $errores));
}

// Escapar para el HTML del correo
$e = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$cuerpo = '<p style="font-size:14px">Nuevo mensaje desde el formulario de contacto:</p>'
    . lsMailTable([
        lsRow('Nombre', $nombre),
        lsRow('Correo', $email),
        lsRow('Teléfono', $tel ?: '—'),
        lsRow('Asunto', $asunto),
    ])
    . '<p style="font-size:14px;color:#94A3B8;margin-top:8px">Mensaje:</p>'
    . '<div style="background:#0f0f1a;border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:14px;font-size:14px;line-height:1.5">'
    . nl2br($e($mensaje)) . '</div>';

list($ok, $err) = lsNotifyAdmin('📩 Contacto web: ' . $asunto, $cuerpo);

if ($ok) {
    salir(true, '¡Gracias! Tu mensaje fue enviado. Te responderemos pronto.');
} else {
    http_response_code(500);
    salir(false, 'No se pudo enviar el correo. Intenta por WhatsApp. (' . $err . ')');
}
