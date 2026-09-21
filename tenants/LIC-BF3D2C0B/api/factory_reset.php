<?php
/* ============================================
   FREAKERS POS - Factory Reset API
   Restores all branding/theming to defaults
   ============================================ */

require_once 'config.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonOutput(['exito' => false, 'mensaje' => 'Método no permitido.']);
}

$sesion = requireAdmin();

$defaults = [
    'NOMBRE_NEGOCIO'   => 'Freakers',
    'NOMBRE_RESTAURANTE' => 'FREAKERS',
    'RAZON_SOCIAL'     => 'BURGERS AND FAST FOOD',
    'NIT'              => '1214722370',
    'DIRECCION'        => 'Medellin, Colombia',
    'TELEFONO'        => '310 574 3129',
    'SUBTITULO'        => 'Burgers & Fast Food',
    'LOGO'             => 'https://i.ibb.co/39bHjfNc/freakers-png.png',
    'COLOR_PRIMARIO'  => '#6C3CE1',
    'COLOR_SECUNDARIO' => '#1A1A2E',
    'COLOR_ACENTO'    => '#E94560',
    'COLOR_FONDO'     => '#16213E',
    'TIPOGRAFIA'       => 'Inter',
    'VALOR_DOMICILIO'  => '5000',
    'AUTO_IMPRIMIR'   => 'SI',
    'MONEDA'          => 'COP',
    'IMPUESTO'        => '0',
    'PEDIDOS_COCINA'  => 'SI',
];

foreach ($defaults as $key => $val) {
    $stmt = $pdo->prepare('INSERT INTO CONFIGURACION (parametro, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?');
    $stmt->execute([$key, $val, $val]);
}

registrarMovimiento($pdo, $sesion['idUsuario'], 'FACTORY_RESET', 'CONFIG', 'Restauro configuración de fabrica');
jsonOutput(['exito' => true, 'mensaje' => 'Configuracion restaurada a estado de fabrica. Recarga la pagina para ver los cambios.', 'defaults' => $defaults]);