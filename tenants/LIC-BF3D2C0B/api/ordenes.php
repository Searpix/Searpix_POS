<?php
require_once 'config.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $sesion = requireAuth();
        $action = trim($_GET['action'] ?? '');

        switch ($action) {
            case 'mesa':
                $idMesa = trim($_GET['idMesa'] ?? '');
                if (!$idMesa) jsonOutput(['exito' => false, 'mensaje' => 'Mesa requerida.']);
                $stmt = $pdo->prepare('SELECT o.*, u.usuario FROM ORDENES o LEFT JOIN USUARIOS u ON o.idUsuario = u.idUsuario WHERE o.idMesa = ? AND o.estado NOT IN ("PAGADO","ELIMINADA","CANCELADO","COMPLETADO") ORDER BY o.fecha DESC LIMIT 1');
                $stmt->execute([$idMesa]);
                $orden = $stmt->fetch();
                if ($orden) {
                    $stmt2 = $pdo->prepare('SELECT * FROM DETALLE_ORDEN WHERE idOrden = ? ORDER BY idDetalle');
                    $stmt2->execute([$orden['idOrden']]);
                    $orden['items'] = $stmt2->fetchAll();
                }
                jsonOutput($orden ?: null);
                break;

            case 'domicilios':
                $canal = trim($_GET['canal'] ?? '');
                $sql = 'SELECT o.*, u.usuario FROM ORDENES o LEFT JOIN USUARIOS u ON o.idUsuario = u.idUsuario WHERE o.idMesa = "DOMICILIO"';
                $params = [];
                if ($canal) {
                    $sql .= ' AND o.canal = ?';
                    $params[] = $canal;
                }
                $sql .= ' AND o.estado NOT IN ("ELIMINADA","CANCELADO","COMPLETADO") ORDER BY o.fecha DESC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $ordenes = $stmt->fetchAll();
                foreach ($ordenes as &$o) {
                    $stmt2 = $pdo->prepare('SELECT * FROM DETALLE_ORDEN WHERE idOrden = ? ORDER BY idDetalle');
                    $stmt2->execute([$o['idOrden']]);
                    $o['items'] = $stmt2->fetchAll();
                }
                jsonOutput($ordenes);
                break;

            case 'todas':
                $fecha = trim($_GET['fecha'] ?? date('Y-m-d'));
                $estado = trim($_GET['estado'] ?? '');
                $tipo = trim($_GET['tipo'] ?? '');
                $sql = 'SELECT o.*, u.usuario FROM ORDENES o LEFT JOIN USUARIOS u ON o.idUsuario = u.idUsuario WHERE DATE(o.fecha) = ?';
                $params = [$fecha];
                if ($estado) {
                    $sql .= ' AND o.estado = ?';
                    $params[] = $estado;
                }
                if ($tipo === 'MESA') {
                    $sql .= ' AND o.idMesa != "DOMICILIO"';
                } elseif ($tipo === 'DOMICILIO') {
                    $sql .= ' AND o.idMesa = "DOMICILIO"';
                }
                $sql .= ' ORDER BY o.fecha DESC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $ordenes = $stmt->fetchAll();
                foreach ($ordenes as &$o) {
                    $stmt2 = $pdo->prepare('SELECT * FROM DETALLE_ORDEN WHERE idOrden = ? ORDER BY idDetalle');
                    $stmt2->execute([$o['idOrden']]);
                    $o['items'] = $stmt2->fetchAll();
                }
                jsonOutput($ordenes);
                break;

            case 'una':
                $id = trim($_GET['id'] ?? '') ?: trim($_GET['idOrden'] ?? '');
                if (!$id) jsonOutput(['exito' => false, 'mensaje' => 'ID requerido.']);
                $stmt = $pdo->prepare('SELECT o.*, u.usuario FROM ORDENES o LEFT JOIN USUARIOS u ON o.idUsuario = u.idUsuario WHERE o.idOrden = ?');
                $stmt->execute([$id]);
                $orden = $stmt->fetch();
                if ($orden) {
                    $stmt2 = $pdo->prepare('SELECT * FROM DETALLE_ORDEN WHERE idOrden = ? ORDER BY idDetalle');
                    $stmt2->execute([$id]);
                    $orden['items'] = $stmt2->fetchAll();
                }
                jsonOutput($orden ?: null);
                break;

            default:
                jsonOutput(['exito' => false, 'mensaje' => 'Accion GET no reconocida.']);
        }
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        $action = trim($input['action'] ?? '');

        switch ($action) {
            case 'crear':
                try {
                $sesion = requireAuth();
                $idMesa = trim($input['idMesa'] ?? '');
                $items = $input['items'] ?? [];
                $descuento = (float)($input['descuento'] ?? 0);
                $comentario = trim($input['comentario'] ?? '');
                $canal = trim($input['canal'] ?? 'LOCAL');
                $idApp = trim($input['idApp'] ?? '') ?: null;
                $cliente_nombre = trim($input['cliente_nombre'] ?? '') ?: null;
                $cliente_telefono = trim($input['cliente_telefono'] ?? '') ?: null;
                $cliente_direccion = trim($input['cliente_direccion'] ?? '') ?: null;

                if (empty($idMesa)) jsonOutput(['exito' => false, 'mensaje' => 'Mesa requerida.']);
                if (empty($items)) jsonOutput(['exito' => false, 'mensaje' => 'Debe agregar al menos un producto.']);

                $idOrden = 'ORD-' . strtoupper(substr(uniqid(), -6));
                $egreso = (float)($input['egreso'] ?? 0);
                $subtotal = 0;
                foreach ($items as $it) {
                    $subtotal += (float)$it['precioUnitario'] * (int)$it['cantidad'];
                }
                $total = max(0, $subtotal - $descuento - $egreso);

                // Detectar columnas reales de la tabla ORDENES
                $cols_raw = $pdo->query("SHOW COLUMNS FROM ORDENES")->fetchAll(PDO::FETCH_COLUMN);
                $cols = array_map('strtolower', $cols_raw);

                // Validate idUsuario exists in DB (prevent FK error if user deleted)
                $idUserVal = null;
                if (!empty($sesion['idUsuario'])) {
                    $chk = $pdo->prepare('SELECT idUsuario FROM USUARIOS WHERE idUsuario = ?');
                    $chk->execute([$sesion['idUsuario']]);
                    $idUserVal = $chk->fetch() ? $sesion['idUsuario'] : null;
                }

                $fields = ['idorden','idmesa','idusuario','subtotal','descuento','total','egreso','comentario','canal','idapp','estado'];
                $params = [$idOrden, $idMesa, $idUserVal, $subtotal, $descuento, $total, $egreso, $comentario, $canal, $idApp, 'PENDIENTE'];

                if (in_array('cliente_nombre', $cols)) {
                    $fields[] = 'cliente_nombre';
                    $params[] = $cliente_nombre;
                }
                if (in_array('cliente_telefono', $cols)) {
                    $fields[] = 'cliente_telefono';
                    $params[] = $cliente_telefono;
                }
                if (in_array('cliente_direccion', $cols)) {
                    $fields[] = 'cliente_direccion';
                    $params[] = $cliente_direccion;
                }

                $domicilio_fee = (float)($input['domicilio'] ?? 0);
                if (in_array('domicilio', $cols)) {
                    $fields[] = 'domicilio';
                    $params[] = $domicilio_fee;
                    $total = $total + $domicilio_fee;
                }

                $colList = implode(', ', $fields);
                $placeholders = implode(', ', array_fill(0, count($params), '?'));
                $stmt = $pdo->prepare("INSERT INTO ORDENES ($colList) VALUES ($placeholders)");
                $stmt->execute($params);

                foreach ($items as $it) {
                    $idDet = 'DET-' . strtoupper(substr(uniqid(), -6));
                    $linea = (float)$it['precioUnitario'] * (int)$it['cantidad'];
                    $stmt2 = $pdo->prepare('INSERT INTO DETALLE_ORDEN (idDetalle, idOrden, idProducto, cantidad, precioUnitario, totalLinea, producto) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt2->execute([$idDet, $idOrden, $it['idProducto'], (int)$it['cantidad'], (float)$it['precioUnitario'], $linea, $it['producto'] ?? null]);
                }

                if ($idMesa !== 'DOMICILIO') {
                    $pdo->prepare('UPDATE MESAS SET estado = "OCUPADA" WHERE idMesa = ?')->execute([$idMesa]);
                }

                $idNotif = 'NOT-' . strtoupper(substr(uniqid(), -6));
                $pdo->prepare('INSERT INTO NOTIFICACIONES (idNotificacion, tipo, titulo, mensaje, idReferencia) VALUES (?, ?, ?, ?, ?)')->execute([
                    $idNotif, 'NUEVA_ORDEN', 'Nueva orden', $idOrden . ($canal !== 'LOCAL' ? ' via ' . $canal : ''), $idOrden
                ]);

                registrarMovimiento($pdo, $sesion['idUsuario'], 'CREAR_ORDEN', $idOrden, 'Orden creada: ' . $idOrden . ' Mesa: ' . $idMesa . ' Total: $' . number_format($total));
                jsonOutput(['exito' => true, 'idOrden' => $idOrden, 'total' => $total]);
                } catch (PDOException $e) {
                    error_log('[Comandix][pos-ordenes] ' . $e->getMessage());
                    jsonOutput(['exito' => false, 'mensaje' => 'Error al crear la orden.']);
                } catch (Exception $e) {
                    error_log('[Comandix][pos-ordenes] ' . $e->getMessage());
                    jsonOutput(['exito' => false, 'mensaje' => 'Error al crear la orden.']);
                }
                break;

            case 'agregar':
                $sesion = requireAuth();
                $idOrden = trim($input['idOrden'] ?? '');
                $items = $input['items'] ?? [];

                if (!$idOrden) jsonOutput(['exito' => false, 'mensaje' => 'ID orden requerido.']);

                foreach ($items as $it) {
                    $idDet = 'DET-' . strtoupper(substr(uniqid(), -6));
                    $linea = (float)$it['precioUnitario'] * (int)$it['cantidad'];
                    $stmt = $pdo->prepare('INSERT INTO DETALLE_ORDEN (idDetalle, idOrden, idProducto, cantidad, precioUnitario, totalLinea, producto) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$idDet, $idOrden, $it['idProducto'], (int)$it['cantidad'], (float)$it['precioUnitario'], $linea, $it['producto'] ?? null]);
                }

                // Recalculate total
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(totalLinea),0) as subt FROM DETALLE_ORDEN WHERE idOrden = ?');
                $stmt->execute([$idOrden]);
                $subt = (float)$stmt->fetch()['subt'];
                $stmt = $pdo->prepare('SELECT descuento, domicilio FROM ORDENES WHERE idOrden = ?');
                $stmt->execute([$idOrden]);
                $ordRow = $stmt->fetch();
                $desc = (float)($ordRow['descuento'] ?? 0);
                $dom = (float)($ordRow['domicilio'] ?? 0);
                $total = max(0, $subt - $desc) + $dom;
                $pdo->prepare('UPDATE ORDENES SET subtotal=?, total=? WHERE idOrden=?')->execute([$subt, $total, $idOrden]);

                registrarMovimiento($pdo, $sesion['idUsuario'], 'AGREGAR_ITEMS', $idOrden, 'Agrego items a orden ' . $idOrden);
                jsonOutput(['exito' => true, 'total' => $total]);
                break;

            case 'cobrar':
                $sesion = requireAuth();
                $idOrden = trim($input['idOrden'] ?? '');
                $metodo = trim($input['metodo'] ?? '') ?: trim($input['metodoPago'] ?? '');
                $monto = (float)($input['monto'] ?? 0);

                if (!$idOrden || !$metodo) jsonOutput(['exito' => false, 'mensaje' => 'Orden y metodo requeridos.']);

                $stmt = $pdo->prepare('SELECT * FROM ORDENES WHERE idOrden = ?');
                $stmt->execute([$idOrden]);
                $orden = $stmt->fetch();
                if (!$orden) jsonOutput(['exito' => false, 'mensaje' => 'Orden no encontrada.']);

                if ($monto <= 0) $monto = (float)$orden['total'];

                // Validate idUsuario exists
                $idUserPago = null;
                if (!empty($sesion['idUsuario'])) {
                    $chk = $pdo->prepare('SELECT idUsuario FROM USUARIOS WHERE idUsuario = ?');
                    $chk->execute([$sesion['idUsuario']]);
                    $idUserPago = $chk->fetch() ? $sesion['idUsuario'] : null;
                }

                $idPago = 'PAY-' . strtoupper(substr(uniqid(), -6));
                $stmt = $pdo->prepare('INSERT INTO PAGOS (idPago, idOrden, metodo, monto, idUsuario) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$idPago, $idOrden, $metodo, $monto, $idUserPago]);

                $nuevoEstado = ($orden['canal'] ?? 'LOCAL') !== 'LOCAL' ? 'PAGADO' : 'PAGADO';
                $pdo->prepare('UPDATE ORDENES SET estado = ? WHERE idOrden = ?')->execute([$nuevoEstado, $idOrden]);

                if ($orden['idMesa'] !== 'DOMICILIO') {
                    $pdo->prepare('UPDATE MESAS SET estado = "LIBRE" WHERE idMesa = ?')->execute([$orden['idMesa']]);
                }

                registrarMovimiento($pdo, $sesion['idUsuario'], 'COBRAR', $idOrden, 'Cobro orden ' . $idOrden . ' $' . number_format($monto) . ' via ' . $metodo);
                jsonOutput(['exito' => true, 'idPago' => $idPago]);
                break;

            case 'cancelar':
                $sesion = requireAuth();
                $idOrden = trim($input['idOrden'] ?? '');
                if (!$idOrden) jsonOutput(['exito' => false, 'mensaje' => 'ID requerido.']);

                $stmt = $pdo->prepare('SELECT * FROM ORDENES WHERE idOrden = ?');
                $stmt->execute([$idOrden]);
                $orden = $stmt->fetch();
                if (!$orden) jsonOutput(['exito' => false, 'mensaje' => 'Orden no encontrada.']);

                $pdo->prepare('UPDATE ORDENES SET estado = "CANCELADO" WHERE idOrden = ?')->execute([$idOrden]);
                if ($orden['idMesa'] !== 'DOMICILIO') {
                    $pdo->prepare('UPDATE MESAS SET estado = "LIBRE" WHERE idMesa = ?')->execute([$orden['idMesa']]);
                }

                registrarMovimiento($pdo, $sesion['idUsuario'], 'CANCELAR_ORDEN', $idOrden, 'Cancelo orden ' . $idOrden);
                jsonOutput(['exito' => true]);
                break;

            case 'actualizarEstado':
                $sesion = requireAuth();
                $idOrden = trim($input['idOrden'] ?? '');
                $nuevoEstado = trim($input['estado'] ?? '');
                if (!$idOrden || !$nuevoEstado) jsonOutput(['exito' => false, 'mensaje' => 'ID y estado requeridos.']);

                $pdo->prepare('UPDATE ORDENES SET estado = ? WHERE idOrden = ?')->execute([$nuevoEstado, $idOrden]);
                registrarMovimiento($pdo, $sesion['idUsuario'], 'CAMBIAR_ESTADO', $idOrden, 'Orden ' . $idOrden . ' -> ' . $nuevoEstado);
                jsonOutput(['exito' => true]);
                break;

            case 'editar':
                $sesion = requireAuth();
                $idOrden = trim($input['idOrden'] ?? '');
                if (!$idOrden) jsonOutput(['exito' => false, 'mensaje' => 'ID orden requerido.']);
                $mesa = trim($input['mesa'] ?? '');
                $total = (float)($input['total'] ?? 0);
                $domicilio = (float)($input['domicilio'] ?? 0);
                $estado = trim($input['estado'] ?? '');
                // Check which columns exist in ORDENES table
                $cols_raw = $pdo->query("SHOW COLUMNS FROM ORDENES")->fetchAll(PDO::FETCH_COLUMN);
                $cols = array_map('strtolower', $cols_raw);
                if (!empty($mesa)) {
                    $pdo->prepare('UPDATE ORDENES SET idMesa = ? WHERE idOrden = ?')->execute([$mesa, $idOrden]);
                }
                if ($total > 0) {
                    $pdo->prepare('UPDATE ORDENES SET total = ? WHERE idOrden = ?')->execute([$total, $idOrden]);
                }
                if (in_array('domicilio', $cols)) {
                    $pdo->prepare('UPDATE ORDENES SET domicilio = ? WHERE idOrden = ?')->execute([$domicilio, $idOrden]);
                }
                if (!empty($estado)) {
                    $pdo->prepare('UPDATE ORDENES SET estado = ? WHERE idOrden = ?')->execute([$estado, $idOrden]);
                }
                registrarMovimiento($pdo, $sesion['idUsuario'], 'EDITAR_ORDEN', $idOrden, 'Edito orden ' . $idOrden);
                jsonOutput(['exito' => true, 'mensaje' => 'Orden actualizada.']);
                break;

            case 'eliminar':
                $sesion = requireAuth();
                $idOrden = trim($input['idOrden'] ?? '');
                if (!$idOrden) jsonOutput(['exito' => false, 'mensaje' => 'ID requerido.']);
                $stmt = $pdo->prepare('SELECT * FROM ORDENES WHERE idOrden = ?');
                $stmt->execute([$idOrden]);
                $orden = $stmt->fetch();
                if (!$orden) jsonOutput(['exito' => false, 'mensaje' => 'Orden no encontrada.']);
                $pdo->prepare('UPDATE ORDENES SET estado = "ELIMINADA" WHERE idOrden = ?')->execute([$idOrden]);
                if ($orden['idMesa'] !== 'DOMICILIO') {
                    $pdo->prepare('UPDATE MESAS SET estado = "LIBRE" WHERE idMesa = ?')->execute([$orden['idMesa']]);
                }
                registrarMovimiento($pdo, $sesion['idUsuario'], 'ELIMINAR_ORDEN', $idOrden, 'Elimino orden ' . $idOrden);
                jsonOutput(['exito' => true]);
                break;

            default:
                jsonOutput(['exito' => false, 'mensaje' => 'Accion no reconocida.']);
        }
        break;

    default:
        jsonOutput(['exito' => false, 'mensaje' => 'Metodo no permitido.']);
}