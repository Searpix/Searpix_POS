<?php
require_once 'config.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $sesion = requireAuth();
        $stmt = $pdo->query('SELECT * FROM RESPUESTAS_RAPIDAS ORDER BY orden+0, nombre');
        $respuestas = $stmt->fetchAll();
        foreach ($respuestas as &$r) {
            $r['activo'] = (int)$r['activo'];
        }
        jsonOutput($respuestas);
        break;

    case 'POST':
        $sesion = requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $action = trim($input['action'] ?? '');

        switch ($action) {
            case 'guardar':
                $id = trim($input['id'] ?? '');
                $nombre = trim($input['nombre'] ?? '');
                $mensaje = trim($input['mensaje'] ?? '');
                $variables = trim($input['variables'] ?? '');
                $activo = (int)($input['activo'] ?? 1);
                $orden = (int)($input['orden'] ?? 0);

                if (empty($nombre) || empty($mensaje)) {
                    jsonOutput(['exito' => false, 'mensaje' => 'Nombre y mensaje son obligatorios.']);
                }

                if (empty($id)) {
                    $nuevoId = generarId('RR');
                    $stmt = $pdo->prepare('INSERT INTO RESPUESTAS_RAPIDAS (id, nombre, mensaje, variables, activo, orden) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$nuevoId, $nombre, $mensaje, $variables, $activo, $orden]);
                    registrarMovimiento($pdo, $sesion['idUsuario'], 'CREAR_RESPUESTA_RAPIDA', $nuevoId, 'Creo respuesta rapida: ' . $nombre);
                    jsonOutput(['exito' => true, 'mensaje' => 'Respuesta rapida creada.', 'id' => $nuevoId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE RESPUESTAS_RAPIDAS SET nombre=?, mensaje=?, variables=?, activo=?, orden=? WHERE id=?');
                    $stmt->execute([$nombre, $mensaje, $variables, $activo, $orden, $id]);
                    registrarMovimiento($pdo, $sesion['idUsuario'], 'EDITAR_RESPUESTA_RAPIDA', $id, 'Edito respuesta rapida: ' . $nombre);
                    jsonOutput(['exito' => true, 'mensaje' => 'Respuesta rapida actualizada.']);
                }
                break;

            case 'toggle':
                $id = trim($input['id'] ?? '');
                if (empty($id)) {
                    jsonOutput(['exito' => false, 'mensaje' => 'ID requerido.']);
                }
                $stmt = $pdo->prepare('SELECT activo FROM RESPUESTAS_RAPIDAS WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) {
                    jsonOutput(['exito' => false, 'mensaje' => 'Respuesta rapida no encontrada.']);
                }
                $nuevo = $row['activo'] ? 0 : 1;
                $pdo->prepare('UPDATE RESPUESTAS_RAPIDAS SET activo = ? WHERE id = ?')->execute([$nuevo, $id]);
                jsonOutput(['exito' => true, 'mensaje' => $nuevo ? 'Respuesta activada.' : 'Respuesta desactivada.']);
                break;

            case 'eliminar':
                $id = trim($input['id'] ?? '');
                if (empty($id)) {
                    jsonOutput(['exito' => false, 'mensaje' => 'ID requerido.']);
                }
                $stmt = $pdo->prepare('DELETE FROM RESPUESTAS_RAPIDAS WHERE id = ?');
                $stmt->execute([$id]);
                registrarMovimiento($pdo, $sesion['idUsuario'], 'ELIMINAR_RESPUESTA_RAPIDA', $id, 'Elimino respuesta rapida: ' . $id);
                jsonOutput(['exito' => true, 'mensaje' => 'Respuesta rapida eliminada.']);
                break;

            default:
                jsonOutput(['exito' => false, 'mensaje' => 'Accion no reconocida.']);
        }
        break;

    default:
        jsonOutput(['exito' => false, 'mensaje' => 'Metodo no permitido.']);
}