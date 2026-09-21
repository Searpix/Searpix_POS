<?php
/* ============================================================
   Comandix — Entrega SEGURA de comprobantes de pago
   Los comprobantes NO son accesibles por URL directa
   (storage/comprobantes queda bloqueado por .htaccess).
   Solo un administrador autenticado puede verlos a traves
   de este script, que los transmite desde el sistema de
   archivos (no por web) y valida la ruta para evitar
   "path traversal".
   ============================================================ */

require_once 'config.php';

// Solo administradores autenticados mediante Authorization: Bearer
$auth = lsRequireAuth('admin');

$rel = (string)($_GET['file'] ?? '');

// Normalizar: aceptamos tanto "comprobantes/archivo.jpg" como "archivo.jpg"
$rel = str_replace('\\', '/', $rel);
if (strpos($rel, 'comprobantes/') === 0) {
    $rel = substr($rel, strlen('comprobantes/'));
}

// Solo un nombre de archivo simple: sin barras ni referencias a directorios
if ($rel === '' || strpos($rel, '/') !== false || strpos($rel, "\0") !== false || preg_match('/(^|\/)\.\.?($|\/)/', $rel)) {
    lsJsonOutput(['exito' => false, 'mensaje' => 'Comprobante no valido'], 400);
}

$baseDir = realpath(__DIR__ . '/../storage/comprobantes');
$fullPath = realpath($baseDir . '/' . $rel);

// El archivo debe existir y estar DENTRO de la carpeta de comprobantes
if ($baseDir === false || $fullPath === false || strpos($fullPath, $baseDir . DIRECTORY_SEPARATOR) !== 0) {
    lsJsonOutput(['exito' => false, 'mensaje' => 'Comprobante no encontrado'], 404);
}

// Solo tipos permitidos (imagenes / PDF)
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$tipos = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf'
];
if (!isset($tipos[$ext])) {
    lsJsonOutput(['exito' => false, 'mensaje' => 'Tipo de archivo no permitido'], 415);
}

// Transmitir el archivo de forma segura (en linea, sin cache publica)
header('Content-Type: ' . $tipos[$ext]);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="comprobante.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
readfile($fullPath);
exit;
