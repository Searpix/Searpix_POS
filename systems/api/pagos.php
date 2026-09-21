<?php
require_once 'config.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // obtenerTodosLosPagos
        $stmt = $pdo->query('SELECT p.*, u.nombre as usuarioNombre, u.apellido FROM PAGOS p LEFT JOIN USUARIOS u ON p.idUsuario = u.idUsuario ORDER BY p.fecha DESC');
        $pagos = $stmt->fetchAll();
        foreach ($pagos as &$p) {
            $p['idPago'] = $p['idPago'];
            $p['fecha'] = $p['fecha'];
            $p['metodo'] = $p['metodo'];
            $p['valor'] = (float)$p['monto'];
            $p['usuario'] = trim(($p['usuarioNombre'] ?? '') . ' ' . ($p['apellido'] ?? ''));
        }
        jsonOutput($pagos);
        break;

    case 'POST':
        $sesion = requireAuth();
        $input = json_decode(file_get_contents('php://input'), true);
        $idOrden = trim($input['idOrden'] ?? '');
        $metodo = strtoupper(trim($input['metodo'] ?? ''));
        $valor = (float)($input['valor'] ?? 0);
        // Validate idUsuario exists in DB (prevent FK error if user deleted)
        $idUsuario = null;
        if (!empty($sesion['idUsuario'])) {
            $chk = $pdo->prepare('SELECT idUsuario FROM USUARIOS WHERE idUsuario = ?');
            $chk->execute([$sesion['idUsuario']]);
            $idUsuario = $chk->fetch() ? $sesion['idUsuario'] : null;
        }

        if (empty($idOrden) || $valor <= 0) jsonOutput(['exito' => false, 'mensaje' => 'Debe seleccionar una orden válida.']);

        $idPago = generarId('PAG');
        $stmt = $pdo->prepare('INSERT INTO PAGOS (idPago, idOrden, fecha, metodo, monto, idUsuario) VALUES (?, ?, NOW(), ?, ?, ?)');
        $stmt->execute([$idPago, $idOrden, $metodo, $valor, $idUsuario]);

        // Mark order as paid
        $stmt = $pdo->prepare('UPDATE ORDENES SET estado = ? WHERE idOrden = ?');
        $stmt->execute(['PAGADO', $idOrden]);

        // Free mesa if not domicilio
        $stmt = $pdo->prepare('SELECT idMesa FROM ORDENES WHERE idOrden = ?');
        $stmt->execute([$idOrden]);
        $ord = $stmt->fetch();
        if ($ord && strtoupper($ord['idMesa']) !== 'DOMICILIO') {
            $stmt = $pdo->prepare('UPDATE MESAS SET estado = ? WHERE idMesa = ?');
            $stmt->execute(['LIBRE', $ord['idMesa']]);
        }

        registrarMovimiento($pdo, $sesion['idUsuario'], 'REGISTRAR_PAGO', $idOrden, 'Registró pago de $' . $valor . ' vía ' . $metodo);

        // Notification
        $notifId = generarId('NOT');
        $stmt = $pdo->prepare('INSERT INTO NOTIFICACIONES (idNotificacion, tipo, titulo, mensaje, idReferencia) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$notifId, 'PAGO', 'Pago Registrado', 'Orden ' . $idOrden . ' - $' . $valor . ' vía ' . $metodo, $idOrden]);

        jsonOutput(['exito' => true, 'mensaje' => 'Pago registrado exitosamente.']);
        break;
}
