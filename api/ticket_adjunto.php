<?php
/* ============================================================
   Comandix — Entrega SEGURA de adjuntos de tickets
   Los adjuntos NO son accesibles por URL directa
   (storage/tickets queda fuera del alcance web y ademas
   .htaccess bloquea ejecucion). Solo un usuario autenticado
   con acceso al ticket (admin, o el cliente dueño) puede
   verlos a traves de este script, que valida sesion, rol,
   propiedad del ticket y evita "path traversal".
   El token puede venir por header Authorization o por el
   parametro ?tk= (necesario para <img>/<a> que no envian
   cabeceras personalizadas).
   ============================================================ */

require_once 'config.php';

// --- Token: query (?tk=) o header Authorization ---
$token = trim($_GET['tk'] ?? '');
if ($token === '') { $token = lsGetBearerToken(); }
$payload = $token !== '' ? lsVerifyToken($token) : null;
if (!$payload) { lsJsonOutput(['exito' => false, 'mensaje' => 'No autenticado.'], 401); }

$tipoUsuario = $payload['type'] ?? '';
$idUsuario   = $payload['id'] ?? '';

// --- Parametros ---
$ticket = (string)($_GET['ticket'] ?? '');
$file   = (string)($_GET['file'] ?? '');

$ticketSafe = preg_replace('/[^A-Za-z0-9_-]/', '', $ticket);
if ($ticketSafe === '' || $ticketSafe !== $ticket) {
    lsJsonOutput(['exito' => false, 'mensaje' => 'Ticket no valido.'], 400);
}
$file = str_replace('\\', '/', $file);
if ($file === '' || strpos($file, '/') !== false || strpos($file, "\0") !== false || preg_match('/(^|\/)\.\.?($|\/)/', $file)) {
    lsJsonOutput(['exito' => false, 'mensaje' => 'Archivo no valido.'], 400);
}

// --- Sesion activa ---
$pdo = lsGetConnection();
$st = $pdo->prepare('SELECT 1 FROM SESIONES WHERE jti=? AND tipo=? AND idUsuario=? AND revocado IS NULL AND expira>NOW()');
$st->execute([$payload['jti'] ?? '', $tipoUsuario, $idUsuario]);
if (!$st->fetchColumn()) { lsJsonOutput(['exito' => false, 'mensaje' => 'Sesion expirada.'], 401); }

// --- Autorizacion por rol / propiedad del ticket ---
if ($tipoUsuario === 'admin') {
    // El soporte puede ver cualquier adjunto
} elseif ($tipoUsuario === 'client') {
    $st = $pdo->prepare('SELECT 1 FROM TICKETS WHERE idTicket=? AND idCliente=?');
    $st->execute([$ticketSafe, $idUsuario]);
    if (!$st->fetchColumn()) { lsJsonOutput(['exito' => false, 'mensaje' => 'Sin acceso a este adjunto.'], 403); }
} else {
    lsJsonOutput(['exito' => false, 'mensaje' => 'Sin acceso a este adjunto.'], 403);
}

// --- Resolver ruta dentro de storage/tickets/<idTicket> ---
$baseDir  = realpath(__DIR__ . '/../storage/tickets/' . $ticketSafe);
$fullPath = $baseDir !== false ? realpath($baseDir . '/' . $file) : false;
if ($baseDir === false || $fullPath === false || strpos($fullPath, $baseDir . DIRECTORY_SEPARATOR) !== 0) {
    lsJsonOutput(['exito' => false, 'mensaje' => 'Adjunto no encontrado.'], 404);
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$tipos = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
    'txt' => 'text/plain', 'csv' => 'text/csv',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip' => 'application/zip'
];
if (!isset($tipos[$ext])) { lsJsonOutput(['exito' => false, 'mensaje' => 'Tipo de archivo no permitido.'], 415); }

$forzarDescarga = isset($_GET['dl']);
header('Content-Type: ' . $tipos[$ext]);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: ' . ($forzarDescarga ? 'attachment' : 'inline') . '; filename="adjunto.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
readfile($fullPath);
exit;
