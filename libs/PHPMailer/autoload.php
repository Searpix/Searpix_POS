<?php
/* Autoloader mínimo para PHPMailer (sin Composer).
   Incluye solo las clases necesarias para enviar por SMTP. */
require_once __DIR__ . '/src/Exception.php';
require_once __DIR__ . '/src/PHPMailer.php';
require_once __DIR__ . '/src/SMTP.php';
