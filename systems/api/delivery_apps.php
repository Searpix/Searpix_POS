<?php
require_once 'config.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $sesion = requireAuth();
        // FIX BUG 8: SQL column is 'activo' (matching schema), read as 'activo'
        $stmt = $pdo->query('SELECT idApp, nombre, logo, activo, comision, credencial, orden, webhook_url, api_key FROM APPS_DELIVERY ORDER BY orden+0, nombre');
        $apps = $stmt->fetchAll();
        foreach ($apps as &$a) {
            $a['activo'] = (int)$a['activo'];
            $a['comision'] = (float)$a['comision'];
        }
        jsonOutput($apps);
        break;

    case 'POST':
        $sesion = requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $action = trim($input['action'] ?? '');

        switch ($action) {
            case 'guardar':
                $idApp = trim($input['idApp'] ?? '');
                $nombre = trim($input['nombre'] ?? '');
                $logo = trim($input['logo'] ?? '');
                $activo = (int)($input['activo'] ?? 1);
                $comision = (float)($input['comision'] ?? 0);
                $credencial = trim($input['credencial'] ?? '');
                $webhook_url = trim($input['webhook_url'] ?? '');
                $api_key = trim($input['api_key'] ?? '');

                if (empty($idApp) || empty($nombre)) {
                    jsonOutput(['exito' => false, 'mensaje' => 'ID y nombre son obligatorios.']);
                }

                $stmt = $pdo->prepare('INSERT INTO APPS_DELIVERY (idApp, nombre, logo, activo, comision, credencial, webhook_url, api_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), logo=VALUES(logo), activo=VALUES(activo), comision=VALUES(comision), credencial=VALUES(credencial), webhook_url=VALUES(webhook_url), api_key=VALUES(api_key)');
                $stmt->execute([$idApp, $nombre, $logo, $activo, $comision, $credencial, $webhook_url, $api_key]);

                registrarMovimiento($pdo, $sesion['idUsuario'], 'EDITAR_APP_DELIVERY', $idApp, 'Configuro app de delivery: ' . $nombre . ' (comision: ' . $comision . '%)');
                jsonOutput(['exito' => true, 'mensaje' => 'App de delivery guardada correctamente.']);
                break;

            case 'toggle':
                $idApp = trim($input['idApp'] ?? '');
                if (empty($idApp)) {
                    jsonOutput(['exito' => false, 'mensaje' => 'ID de app requerido.']);
                }
                $stmt = $pdo->prepare('SELECT activo FROM APPS_DELIVERY WHERE idApp = ?');
                $stmt->execute([$idApp]);
                $app = $stmt->fetch();
                if (!$app) {
                    jsonOutput(['exito' => false, 'mensaje' => 'App no encontrada.']);
                }
                $nuevo = $app['activo'] ? 0 : 1;
                $stmt = $pdo->prepare('UPDATE APPS_DELIVERY SET activo = ? WHERE idApp = ?');
                $stmt->execute([$nuevo, $idApp]);
                jsonOutput(['exito' => true, 'mensaje' => $nuevo ? 'App activada.' : 'App desactivada.']);
                break;

            case 'eliminar':
                $idApp = trim($input['idApp'] ?? '');
                if (empty($idApp)) {
                    jsonOutput(['exito' => false, 'mensaje' => 'ID de app requerido.']);
                }
                $stmt = $pdo->prepare('DELETE FROM APPS_DELIVERY WHERE idApp = ?');
                $stmt->execute([$idApp]);
                registrarMovimiento($pdo, $sesion['idUsuario'], 'ELIMINAR_APP_DELIVERY', $idApp, 'Elimino app de delivery: ' . $idApp);
                jsonOutput(['exito' => true, 'mensaje' => 'App eliminada.']);
                break;

            default:
                jsonOutput(['exito' => false, 'mensaje' => 'Accion no reconocida.']);
        }
        break;

    default:
        jsonOutput(['exito' => false, 'mensaje' => 'Metodo no permitido.']);
}