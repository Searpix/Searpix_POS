<?php
/* ============================================
   FREAKERS POS - Image Upload API (endurecido)
   Valida el contenido real del archivo, no confia
   en el tipo/nombre enviado por el cliente.
   ============================================ */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
// CORS controlado en config.php (refleja origen permitido). No usar '*'.
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$sesion = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['exito' => false, 'mensaje' => 'Metodo no permitido.']);
}

if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
    $errCode = isset($_FILES['imagen']) ? $_FILES['imagen']['error'] : -1;
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamano maximo del servidor.',
        UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamano maximo permitido.',
        UPLOAD_ERR_PARTIAL => 'El archivo se subio parcialmente.',
        UPLOAD_ERR_NO_FILE => 'No se selecciono ningun archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal.',
        UPLOAD_ERR_CANT_WRITE => 'Error al escribir archivo.',
        UPLOAD_ERR_EXTENSION => 'Extension no permitida.',
    ];
    $msg = isset($errors[$errCode]) ? $errors[$errCode] : 'Error desconocido al subir archivo.';
    jsonOutput(['exito' => false, 'mensaje' => $msg]);
}

$file = $_FILES['imagen'];
$maxSize = 5 * 1024 * 1024; // 5MB

if ($file['size'] <= 0 || $file['size'] > $maxSize) {
    jsonOutput(['exito' => false, 'mensaje' => 'La imagen no debe superar 5MB.']);
}

// Asegurar que provenga de una subida HTTP real.
if (!is_uploaded_file($file['tmp_name'])) {
    jsonOutput(['exito' => false, 'mensaje' => 'Subida invalida.']);
}

/*
 * Validacion basada en el CONTENIDO real del archivo.
 * - Se ignora $file['type'] y la extension del nombre (los controla el cliente).
 * - Solo se aceptan mapas de bits raster verificables con getimagesize().
 * - Se PROHIBE SVG: es XML y puede contener <script> (XSS almacenado).
 */
$allowed = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
];

$info = @getimagesize($file['tmp_name']);
if ($info === false || !isset($info[2]) || !isset($allowed[$info[2]])) {
    jsonOutput(['exito' => false, 'mensaje' => 'Solo se permiten imagenes reales JPG, PNG, GIF o WebP.']);
}
$detectedType = $info[2];

// Doble verificacion con finfo (MIME real por magic bytes).
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $mimeOk = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($realMime, $mimeOk, true)) {
        jsonOutput(['exito' => false, 'mensaje' => 'El contenido del archivo no es una imagen valida.']);
    }
}

// Nombre 100% aleatorio + extension derivada del tipo REAL detectado.
$ext = $allowed[$detectedType];
try {
    $rand = bin2hex(random_bytes(16));
} catch (Exception $e) {
    $rand = uniqid('img_', true);
}
$safeName = 'img_' . $rand . '.' . $ext;

$uploadDir = __DIR__ . '/../assets/img/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Asegurar que en la carpeta de subidas NUNCA se ejecute PHP.
$htaccess = $uploadDir . '.htaccess';
if (!file_exists($htaccess)) {
    @file_put_contents($htaccess,
        "# Bloquear ejecucion de codigo en subidas\n" .
        "php_flag engine off\n" .
        "<FilesMatch \"\\.(php|php3|php4|php5|php7|phtml|phar|pht|cgi|pl|py|sh|asp|aspx|jsp)$\">\n" .
        "    Require all denied\n" .
        "</FilesMatch>\n" .
        "AddType text/plain .php .phtml .phar\n"
    );
}

$destPath = $uploadDir . $safeName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    jsonOutput(['exito' => false, 'mensaje' => 'Error al guardar la imagen en el servidor.']);
}
@chmod($destPath, 0644);

$url = 'assets/img/uploads/' . $safeName;
jsonOutput(['exito' => true, 'mensaje' => 'Imagen subida correctamente.', 'url' => $url, 'nombre' => $safeName]);
