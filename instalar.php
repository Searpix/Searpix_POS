<?php
/* ============================================================
   INSTALADOR LOCAL COMANDIX  (uso unico en XAMPP)
   ------------------------------------------------------------
   Crea AUTOMATICAMENTE las 2 bases de datos que necesita el
   proyecto (freakers_licenses + freakers_pos) ejecutando el
   archivo comandix_bases_datos.sql.

   COMO USARLO:
     1) Copia toda la carpeta dentro de C:\\xampp\\htdocs\\ (en la RAIZ).
     2) Enciende Apache y MySQL en el panel de XAMPP.
     3) Abre en el navegador:  http://localhost/instalar.php
     4) Cuando diga \"INSTALACION COMPLETADA\", BORRA este archivo.

   Seguridad: solo se puede ejecutar desde el propio equipo
   (localhost). Aun asi, ELIMINALO despues de instalar.
   ============================================================ */

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$esLocal = (php_sapi_name() === 'cli') || in_array($remote, ['127.0.0.1', '::1', ''], true);

function box($titulo, $html, $color) {
    echo "<div style='border-left:6px solid {$color};background:#1b1230;color:#eee;padding:16px 20px;margin:14px 0;border-radius:10px;font-family:Segoe UI,Arial,sans-serif;'>";
    echo "<h2 style='margin:0 0 8px;color:{$color};font-size:18px;'>{$titulo}</h2><div style='font-size:14px;line-height:1.6;'>{$html}</div></div>";
}

echo "<!doctype html><html lang='es'><head><meta charset='utf-8'><title>Instalador Comandix</title></head>";
echo "<body style='background:#120b1f;margin:0;padding:30px;font-family:Segoe UI,Arial,sans-serif;'>";
echo "<div style='max-width:760px;margin:0 auto;'>";
echo "<h1 style='color:#6C3CE1;font-family:Segoe UI,Arial,sans-serif;'>Instalador Comandix</h1>";

if (!$esLocal) {
    http_response_code(403);
    box('Acceso denegado', 'Este instalador solo puede ejecutarse desde el propio equipo (localhost).', '#E94560');
    echo "</div></body></html>"; exit;
}

/* ---- 1) Leer credenciales de MySQL desde .env (con valores por defecto XAMPP) ---- */
$host = '127.0.0.1'; $port = '3306'; $user = 'root'; $pass = '';
$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || strpos($l, '=') === false) continue;
        list($k, $v) = array_map('trim', explode('=', $l, 2));
        $v = trim($v, "\"'");
        if ($k === 'SETUP_DB_HOST' || $k === 'LS_DB_HOST') $host = $v ?: $host;
        if ($k === 'SETUP_DB_PORT' || $k === 'LS_DB_PORT') $port = $v ?: $port;
        if ($k === 'SETUP_DB_USER') $user = $v;
        if ($k === 'SETUP_DB_PASS') $pass = $v;
    }
}

/* ---- 2) Probar conexion a MySQL (sin seleccionar base) ---- */
try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
} catch (Throwable $e) {
    box('No se pudo conectar a MySQL', 
        'Revisa que <b>MySQL este ENCENDIDO</b> en el panel de XAMPP.<br>' .
        'Usuario probado: <b>' . htmlspecialchars($user) . '</b> en <b>' . htmlspecialchars($host . ':' . $port) . '</b>.<br><br>' .
        'Si tu MySQL tiene contrase&ntilde;a, editala en el archivo <b>.env</b> (l&iacute;neas SETUP_DB_USER / SETUP_DB_PASS) y recarga.<br><br>' .
        'Detalle t&eacute;cnico: <code style="color:#E94560">' . htmlspecialchars($e->getMessage()) . '</code>',
        '#E94560');
    echo "</div></body></html>"; exit;
}

/* ---- 3) Localizar y ejecutar el script SQL ---- */
$sqlFile = __DIR__ . '/comandix_bases_datos.sql';
if (!is_readable($sqlFile)) {
    box('Falta el archivo SQL', 'No se encontro <b>comandix_bases_datos.sql</b> junto a este instalador. Asegurate de copiar TODO el proyecto.', '#E94560');
    echo "</div></body></html>"; exit;
}
$sql = file_get_contents($sqlFile);

try {
    $pdo->exec($sql);
} catch (Throwable $e) {
    box('Error al crear las bases de datos',
        'MySQL rechazo una instrucci&oacute;n del script.<br><br>' .
        'Detalle: <code style="color:#E94560">' . htmlspecialchars($e->getMessage()) . '</code>',
        '#E94560');
    echo "</div></body></html>"; exit;
}

/* ---- 4) Verificar que las tablas clave existen ---- */
$ok = true; $detalle = '';
foreach ([['freakers_licenses', 'ADMIN_USUARIOS'], ['freakers_pos', 'USUARIOS']] as $chk) {
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{$chk[0]}'");
        $n = (int)$st->fetchColumn();
        $detalle .= "Base <b>{$chk[0]}</b>: {$n} tablas creadas.<br>";
        if ($n < 1) $ok = false;
    } catch (Throwable $e) { $ok = false; $detalle .= "Base {$chk[0]}: error de verificacion.<br>"; }
}

if ($ok) {
    box('INSTALACION COMPLETADA',
        $detalle . '<br><b>Ya puedes usar el sistema.</b><br><br>' .
        'Panel de licencias: <a style="color:#6C3CE1" href="/">http://localhost/</a><br>' .
        'Sistema POS: <a style="color:#6C3CE1" href="/systems/">http://localhost/systems/</a><br><br>' .
        'Usuarios iniciales:<br>' .
        '&bull; Licencias &rarr; <b>searpix</b> / <b>Comandix2026*</b><br>' .
        '&bull; POS admin &rarr; <b>SEARPIX</b> / <b>Comandix2026*</b><br>' .
        '&bull; POS mesero &rarr; <b>FREAKERS</b> / <b>Freakers2026*</b><br><br>' .
        '<b style="color:#E94560">Importante:</b> por seguridad, BORRA ahora el archivo <b>instalar.php</b>.',
        '#22c55e');
} else {
    box('Instalaci&oacute;n incompleta', $detalle . '<br>Revisa el detalle anterior.', '#E94560');
}

echo "</div></body></html>";
