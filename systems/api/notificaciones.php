<?php
/* ============================================
   FREAKERS POS v6 - Notifications API
   Actions: listar, count, leer, leerTodas
   ============================================ */

require_once 'config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';

    if ($method === 'GET') {
        $action = trim($_GET['action'] ?? '');
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = trim($input['action'] ?? '');
    }

    $sesion = requireAuth();
    $pdo = getConnection();

    switch ($action) {
        case 'listar':
            $stmt = $pdo->query('SELECT * FROM NOTIFICACIONES ORDER BY fecha DESC LIMIT 50');
            $notificaciones = $stmt->fetchAll();
            jsonOutput(['exito' => true, 'notificaciones' => $notificaciones]);
            break;

        case 'count':
            $stmt = $pdo->query('SELECT COUNT(*) as count FROM NOTIFICACIONES WHERE leida = 0');
            $row = $stmt->fetch();
            jsonOutput(['exito' => true, 'count' => intval($row['count'])]);
            break;

        case 'leer':
            $id = trim($input['idNotificacion'] ?? '');
            if (!$id) jsonOutput(['exito' => false, 'mensaje' => 'ID requerido.']);
            $stmt = $pdo->prepare('UPDATE NOTIFICACIONES SET leida = 1 WHERE idNotificacion = ?');
            $stmt->execute([$id]);
            jsonOutput(['exito' => true]);
            break;

        case 'leerTodas':
            $pdo->exec('UPDATE NOTIFICACIONES SET leida = 1 WHERE leida = 0');
            jsonOutput(['exito' => true]);
            break;

        default:
            jsonOutput(['exito' => false, 'mensaje' => 'Accion no valida.']);
    }
} catch (Exception $e) {
    error_log('[Comandix][pos-notif] ' . $e->getMessage());
    jsonOutput(['exito' => false, 'mensaje' => 'Error del servidor.']);
}
