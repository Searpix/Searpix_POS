<?php
require_once 'config.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sesion = requireAdmin();
    $stmt = $pdo->query('SELECT m.*, u.usuario as usuarioNombre FROM MOVIMIENTOS m LEFT JOIN USUARIOS u ON m.idUsuario = u.idUsuario ORDER BY m.fecha DESC');
    $movimientos = $stmt->fetchAll();
    foreach ($movimientos as &$m) {
        $m['idMovimiento'] = $m['idMovimiento'];
        $m['fecha'] = $m['fecha'];
        $m['usuario'] = $m['usuarioNombre'] ?? $m['idUsuario'];
        $m['accion'] = $m['accion'];
        $m['orden'] = $m['idReferencia'];
        $m['descripcion'] = $m['descripcion'];
    }
    jsonOutput($movimientos);
}
