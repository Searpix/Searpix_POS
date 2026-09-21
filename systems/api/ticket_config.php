<?php
require_once 'config.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $sesion = requireAuth();
        $action = $_GET['action'] ?? 'lista';

        switch ($action) {
            case 'lista':
            default:
                $stmt = $pdo->query('SELECT * FROM TICKET_CONFIG ORDER BY orden ASC');
                $blocks = $stmt->fetchAll();
                foreach ($blocks as &$b) {
                    $b['activo'] = (int)$b['activo'];
                    $b['opciones'] = json_decode($b['opciones'] ?? '{}', true);
                }
                jsonOutput($blocks);
                break;
        }
        break;

    case 'POST':
        $sesion = requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $action = trim($input['action'] ?? '');

        switch ($action) {
            case 'guardar':
                $blocks = $input['blocks'] ?? [];
                if (!is_array($blocks)) {
                    jsonOutput(['exito' => false, 'mensaje' => 'Formato invalido.']);
                }
                // Delete all existing and re-insert
                $pdo->exec('DELETE FROM TICKET_CONFIG');
                $stmt = $pdo->prepare('INSERT INTO TICKET_CONFIG (id, bloque, activo, contenido, orden, opciones) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($blocks as $i => $b) {
                    $id = trim($b['id'] ?? '') ?: generarId('TKT');
                    $bloque = trim($b['bloque'] ?? '');
                    $activo = (int)($b['activo'] ?? 1);
                    $contenido = trim($b['contenido'] ?? '');
                    $orden = (int)($b['orden'] ?? $i);
                    $opciones = json_encode($b['opciones'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $stmt->execute([$id, $bloque, $activo, $contenido, $orden, $opciones]);
                }
                jsonOutput(['exito' => true]);
                break;

            case 'toggle':
                $id = trim($input['id'] ?? '');
                if (!$id) { jsonOutput(['exito' => false, 'mensaje' => 'ID requerido.']); }
                $stmt = $pdo->prepare('SELECT activo FROM TICKET_CONFIG WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) { jsonOutput(['exito' => false, 'mensaje' => 'Bloque no encontrado.']); }
                $newActivo = $row['activo'] ? 0 : 1;
                $upd = $pdo->prepare('UPDATE TICKET_CONFIG SET activo = ? WHERE id = ?');
                $upd->execute([$newActivo, $id]);
                jsonOutput(['exito' => true, 'activo' => $newActivo]);
                break;

            case 'reordenar':
                $orden = $input['orden'] ?? [];
                if (!is_array($orden)) { jsonOutput(['exito' => false, 'mensaje' => 'Formato invalido.']); }
                $stmt = $pdo->prepare('UPDATE TICKET_CONFIG SET orden = ? WHERE id = ?');
                foreach ($orden as $i => $id) {
                    $stmt->execute([$i, trim($id)]);
                }
                jsonOutput(['exito' => true]);
                break;

            default:
                jsonOutput(['exito' => false, 'mensaje' => 'Accion no reconocida.']);
        }
        break;

    default:
        jsonOutput(['exito' => false, 'mensaje' => 'Metodo no permitido.']);
}