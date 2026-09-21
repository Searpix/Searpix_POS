<?php
/* ============================================
   GUARD DE SCRIPTS DESTRUCTIVOS (setup / reset / fix)
   ------------------------------------------------------------
   Estos scripts pueden BORRAR o RECREAR la base de datos, por lo
   que jamas deben quedar accesibles publicamente. Este guard exige:
     1. Que la peticion venga de la propia maquina (loopback).
     2. Un token secreto (SETUP_TOKEN) definido por el operador.
     3. Que no exista un candado de instalacion previa (salvo token).

   Definir el token en systems/api/db_config.php:
       define('SETUP_TOKEN', 'un-valor-largo-y-secreto');
   y llamar por ejemplo:
       http://localhost/searpox/api/setup.php?token=un-valor-largo-y-secreto
   ============================================ */

if (!function_exists('setupGuardDeny')) {
    function setupGuardDeny($msg) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Acceso denegado. ' . $msg;
        exit;
    }
}

// 1) Solo desde la propia maquina (loopback / linea de comandos).
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$esCli  = (php_sapi_name() === 'cli');
$esLocal = in_array($remote, ['127.0.0.1', '::1', ''], true);
if (!$esCli && !$esLocal) {
    setupGuardDeny('Este script solo puede ejecutarse desde el propio servidor (localhost).');
}

// 2) Token secreto obligatorio.
//    Se busca en setup_token.php (editable por el operador) o en db_config.php.
if (!defined('SETUP_TOKEN')) {
    if (file_exists(__DIR__ . '/setup_token.php')) {
        require_once __DIR__ . '/setup_token.php';
    } elseif (file_exists(__DIR__ . '/db_config.php')) {
        require_once __DIR__ . '/db_config.php';
    }
}
if (!defined('SETUP_TOKEN') || SETUP_TOKEN === '' || SETUP_TOKEN === 'CAMBIA_ESTE_TOKEN') {
    setupGuardDeny('SETUP_TOKEN no esta configurado. Definelo en db_config.php antes de continuar.');
}
$tokenRecibido = $_GET['token'] ?? ($_POST['token'] ?? ($_SERVER['HTTP_X_SETUP_TOKEN'] ?? ''));
if (!is_string($tokenRecibido) || !hash_equals(SETUP_TOKEN, $tokenRecibido)) {
    setupGuardDeny('Token de configuracion invalido o ausente.');
}

// 3) Candado de instalacion: evita re-ejecuciones destructivas accidentales.
$__lock = __DIR__ . '/INSTALLED.lock';
if (file_exists($__lock) && empty($_GET['force'])) {
    setupGuardDeny('La instalacion ya se ejecuto antes. Anade &force=1 al token para forzar (BORRA DATOS).');
}
// Registrar el candado tras pasar el guard.
@file_put_contents($__lock, date('c') . ' ejecutado por ' . $remote . "\n", FILE_APPEND);
