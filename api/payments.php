<?php
/* ============================================
   LICENSE SERVER — Payments API
   Admin:  list / approve / reject
   Client: create_order (Wompi/PayPal/Manual),
           wompi_confirm, paypal_capture,
           upload comprobante, list
   ============================================ */

require_once 'config.php';
require_once __DIR__ . '/gateways/wompi.php';
require_once __DIR__ . '/gateways/paypal.php';
require_once __DIR__ . '/mailer.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $pdo = lsGetConnection();
    list($action, $input) = lsGetAction();

    /* ============ Config de pasarelas (para el frontend) ============ */
    if ($action === 'gateway_config') {
        lsRequireAuth('client');
        lsJsonOutput(['exito' => true, 'gateways' => lsGetGatewayConfig()]);
    }

    /* ============ CLIENTE: crear orden de compra ============ */
    if ($action === 'create_order') {
        $auth = lsRequireAuth('client');
        $idPlan  = trim($input['idPlan'] ?? '');
        $periodo = trim($input['periodo'] ?? 'mensual');
        $gateway = strtolower(trim($input['gateway'] ?? 'manual')); // wompi | paypal | manual
        $negocio = trim($input['negocio_nombre'] ?? '');

        if (empty($idPlan)) lsJsonOutput(['exito' => false, 'mensaje' => 'Selecciona un plan.']);

        $stmt = $pdo->prepare('SELECT * FROM PLANES_LICENCIA WHERE idPlan = ? AND activo = 1');
        $stmt->execute([$idPlan]);
        $plan = $stmt->fetch();
        if (!$plan) lsJsonOutput(['exito' => false, 'mensaje' => 'Plan no encontrado.']);

        $duracion = ($periodo === 'anual') ? 12 : 1;
        $monto = ($periodo === 'anual') ? $plan['precio_anual'] : $plan['precio_mensual'];
        $planEnum = ['PLAN-BAS' => 'BASICO', 'PLAN-PRO' => 'PROFESIONAL', 'PLAN-ENT' => 'ENTERPRISE'][$idPlan] ?? 'BASICO';

        // Licencia pendiente
        $idLic = lsGenerarId('LIC-');
        $clave = lsGenerarClave();
        $pdo->prepare('INSERT INTO LICENCIAS
            (idLicencia, clave_activacion, idCliente, idPlan, negocio_nombre, plan, duracion_meses,
             estado, max_usuarios, max_mesas, modulos_habilitados, activaciones, max_activaciones)
            VALUES (?, ?, ?, ?, ?, ?, ?, "PENDIENTE", ?, ?, ?, 0, ?)')
            ->execute([
                $idLic, $clave, $auth['id'], $idPlan, $negocio ?: 'Mi negocio', $planEnum, $duracion,
                $plan['max_usuarios'], $plan['max_mesas'], $plan['modulos'], $plan['max_sucursales']
            ]);

        // Pago pendiente (idPago = referencia de la pasarela)
        $idPago = lsGenerarId('PAY-');
        $metodo = ['wompi' => 'WOMPI', 'paypal' => 'PAYPAL'][$gateway] ?? 'TRANSFERENCIA';
        $pdo->prepare('INSERT INTO PAGOS (idPago, idCliente, idLicencia, idPlan, monto, moneda, metodo, estado)
            VALUES (?, ?, ?, ?, ?, "COP", ?, "PENDIENTE")')
            ->execute([$idPago, $auth['id'], $idLic, $idPlan, $monto, $metodo]);

        lsLog($pdo, 'ORDER_CREATE', "Orden creada: $planEnum ($periodo) — $" . number_format($monto,0,',','.') . " COP — $gateway", $idLic, null, $auth['id']);

        // Notificar al administrador (correo)
        @lsNotifyAdmin('🛒 Nueva orden de licencia — ' . $idPago, lsMailTable([
            lsRow('Orden', $idPago),
            lsRow('Plan', $plan['nombre'] . ' (' . $periodo . ')'),
            lsRow('Monto', '$' . number_format($monto,0,',','.') . ' COP'),
            lsRow('Método', strtoupper($gateway)),
            lsRow('Cliente', $auth['id']),
            lsRow('Licencia', $idLic),
        ]));

        $base = [
            'exito'   => true,
            'idPago'  => $idPago,
            'idLic'   => $idLic,
            'monto'   => floatval($monto),
            'plan'    => $plan['nombre'],
            'periodo' => $periodo,
            'gateway' => $gateway,
        ];

        /* ---- WOMPI: datos para el Widget/Checkout ---- */
        if ($gateway === 'wompi') {
            if (!wompiEnabled()) lsJsonOutput(['exito' => false, 'mensaje' => 'Wompi no está configurado. Agrega tus llaves en config/config.php.']);
            $redirect = LS_BASE_URL . '/views/portal.html?pago=' . $idPago . '&via=wompi';
            $wc = wompiBuildCheckout($idPago, floatval($monto), $redirect);
            $base['wompi'] = $wc;
            lsJsonOutput($base);
        }

        /* ---- PAYPAL: crear Order y devolver su ID ---- */
        if ($gateway === 'paypal') {
            if (!paypalEnabled()) lsJsonOutput(['exito' => false, 'mensaje' => 'PayPal no está configurado. Agrega tus llaves en config/config.php.']);
            $ret = LS_BASE_URL . '/views/portal.html?pago=' . $idPago . '&via=paypal';
            $order = paypalCreateOrder($idPago, floatval($monto), $plan['nombre'] . ' (' . $periodo . ')', $ret, $ret);
            if (!$order || empty($order['id'])) lsJsonOutput(['exito' => false, 'mensaje' => 'No se pudo crear la orden de PayPal.']);
            // Guardar order id
            $pdo->prepare('UPDATE PAGOS SET gateway_ref = ? WHERE idPago = ?')->execute([$order['id'], $idPago]);
            $base['paypal_order_id'] = $order['id'];
            $base['usd'] = paypalCopToUsd(floatval($monto));
            lsJsonOutput($base);
        }

        /* ---- MANUAL (transferencia / Nequi / etc.) ---- */
        $base['mensaje'] = 'Orden creada. Sube tu comprobante para activar la licencia.';
        lsJsonOutput($base);
    }

    /* ============ CLIENTE: confirmar pago Wompi ============ */
    if ($action === 'wompi_confirm') {
        $auth = lsRequireAuth('client');
        $idPago = trim($input['idPago'] ?? '');
        $txId   = trim($input['transaction_id'] ?? '');
        if (empty($idPago)) lsJsonOutput(['exito' => false, 'mensaje' => 'Referencia requerida.']);

        // Consultar transacción en Wompi (por id o por referencia)
        $tx = $txId ? wompiGetTransaction($txId) : wompiGetTransactionByReference($idPago);
        if (!$tx) lsJsonOutput(['exito' => false, 'mensaje' => 'No se encontró la transacción en Wompi.']);

        $status = $tx['status'] ?? 'UNKNOWN';
        $ref    = $tx['reference'] ?? '';
        if ($ref !== $idPago) lsJsonOutput(['exito' => false, 'mensaje' => 'La referencia no coincide.']);

        if ($status === 'APPROVED') {
            lsActivarPago($pdo, $idPago, $auth['id'], 'WOMPI', $tx['id'] ?? '');
            lsJsonOutput(['exito' => true, 'estado' => 'APROBADO', 'mensaje' => '¡Pago aprobado! Tu licencia está activa.']);
        }
        // Otros estados: DECLINED, VOIDED, ERROR, PENDING
        lsJsonOutput(['exito' => false, 'estado' => $status, 'mensaje' => 'El pago no fue aprobado (' . $status . ').']);
    }

    /* ============ CLIENTE: capturar pago PayPal ============ */
    if ($action === 'paypal_capture') {
        $auth = lsRequireAuth('client');
        $idPago  = trim($input['idPago'] ?? '');
        $orderId = trim($input['order_id'] ?? '');
        if (empty($idPago) || empty($orderId)) lsJsonOutput(['exito' => false, 'mensaje' => 'Datos de la orden incompletos.']);

        $cap = paypalCaptureOrder($orderId);
        $status = $cap['status'] ?? 'UNKNOWN';
        if ($status === 'COMPLETED') {
            lsActivarPago($pdo, $idPago, $auth['id'], 'PAYPAL', $orderId);
            lsJsonOutput(['exito' => true, 'estado' => 'APROBADO', 'mensaje' => '¡Pago completado! Tu licencia está activa.']);
        }
        lsJsonOutput(['exito' => false, 'estado' => $status, 'mensaje' => 'El pago de PayPal no se completó (' . $status . ').']);
    }

    /* ============ ADMIN: historial detallado (log) ============ */
    if ($action === 'admin_history') {
        $auth = lsRequireAuth('admin');
        $limit = min(200, max(10, intval($input['limit'] ?? $_GET['limit'] ?? 100)));
        $stmt = $pdo->prepare("SELECT g.*, c.nombre AS cliente_nombre, a.nombre AS admin_nombre, l.clave_activacion
            FROM LICENCIA_LOG g
            LEFT JOIN CLIENTES c ON g.idCliente = c.idCliente
            LEFT JOIN ADMIN_USUARIOS a ON g.idAdmin = a.idAdmin
            LEFT JOIN LICENCIAS l ON g.idLicencia = l.idLicencia
            ORDER BY g.fecha DESC LIMIT " . $limit);
        $stmt->execute();
        lsJsonOutput(['exito' => true, 'eventos' => $stmt->fetchAll()]);
    }

    /* ============ ADMIN: listar pagos ============ */
    if ($action === 'admin_list') {
        $auth = lsRequireAuth('admin');
        $estado = trim($input['estado'] ?? $_GET['estado'] ?? '');
        $where = $estado ? 'WHERE p.estado = ?' : '';
        $params = $estado ? [$estado] : [];

        $stmt = $pdo->prepare("SELECT p.*, c.nombre as cliente_nombre, c.empresa, c.email as cliente_email,
            l.clave_activacion, l.plan, l.negocio_nombre, v.nombre as verificador_nombre
            FROM PAGOS p
            LEFT JOIN CLIENTES c ON p.idCliente = c.idCliente
            LEFT JOIN LICENCIAS l ON p.idLicencia = l.idLicencia
            LEFT JOIN ADMIN_USUARIOS v ON p.verificado_por = v.idAdmin
            $where ORDER BY p.created_at DESC");
        $stmt->execute($params);
        lsJsonOutput(['exito' => true, 'pagos' => $stmt->fetchAll()]);
    }

    /* ============ ADMIN: aprobar pago (manual) ============ */
    if ($action === 'admin_approve') {
        $auth = lsRequireAuth('admin');
        $idPago = trim($input['idPago'] ?? $input['pago_id'] ?? '');
        $notas = trim($input['notas'] ?? '');
        if (empty($idPago)) lsJsonOutput(['exito' => false, 'mensaje' => 'ID de pago requerido.']);

        $stmt = $pdo->prepare('SELECT * FROM PAGOS WHERE idPago = ?');
        $stmt->execute([$idPago]);
        $pago = $stmt->fetch();
        if (!$pago) lsJsonOutput(['exito' => false, 'mensaje' => 'Pago no encontrado.']);
        if ($pago['estado'] !== 'PENDIENTE') lsJsonOutput(['exito' => false, 'mensaje' => 'Este pago ya fue procesado (' . $pago['estado'] . ').']);

        lsActivarPago($pdo, $idPago, null, null, null, $auth['id'], $notas ?: 'Aprobado por admin');
        lsJsonOutput(['exito' => true, 'mensaje' => 'Pago aprobado. Licencia activada.']);
    }

    /* ============ ADMIN: rechazar pago ============ */
    if ($action === 'admin_reject') {
        $auth = lsRequireAuth('admin');
        $idPago = trim($input['idPago'] ?? $input['pago_id'] ?? '');
        $motivo = trim($input['motivo'] ?? $input['notas'] ?? '');
        if (empty($idPago)) lsJsonOutput(['exito' => false, 'mensaje' => 'ID de pago requerido.']);

        $pdo->prepare('UPDATE PAGOS SET estado = "RECHAZADO", fecha_verificacion = NOW(), verificado_por = ?, notas = ? WHERE idPago = ?')
            ->execute([$auth['id'], $motivo ?: 'Rechazado por admin', $idPago]);
        lsLog($pdo, 'PAYMENT_REJECT', "Pago rechazado: $idPago — Motivo: $motivo", null, $auth['id']);
        @lsNotifyAdmin('❌ Pago rechazado — ' . $idPago, lsMailTable([
            lsRow('Orden', $idPago),
            lsRow('Estado', 'RECHAZADO'),
            lsRow('Motivo', $motivo ?: '—'),
        ]));
        lsJsonOutput(['exito' => true, 'mensaje' => 'Pago rechazado.']);
    }

    /* ============ CLIENTE: subir comprobante (manual) ============ */
    if ($action === 'client_upload') {
        $auth = lsRequireAuth('client');
        $idPago = trim($input['idPago'] ?? $_POST['idPago'] ?? '');
        $comprobante = trim($input['comprobante'] ?? '');
        $referencia = trim($input['referencia'] ?? $_POST['referencia'] ?? '');
        $metodo = trim($input['metodo'] ?? $_POST['metodo'] ?? '');
        if (empty($idPago)) lsJsonOutput(['exito' => false, 'mensaje' => 'ID de pago requerido.']);

        if (!empty($_FILES['comprobante']['name'])) {
            $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','pdf','webp'];
            if (!in_array($ext, $allowed)) lsJsonOutput(['exito' => false, 'mensaje' => 'Formato no permitido. Usa JPG, PNG o PDF.']);
            $uploadDir = __DIR__ . '/../storage/comprobantes/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $filename = $idPago . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['comprobante']['tmp_name'], $uploadDir . $filename);
            $comprobante = 'comprobantes/' . $filename;
        }

        $mapMetodo = ['Transferencia'=>'TRANSFERENCIA','Nequi'=>'NEQUI','Daviplata'=>'DAVIPLATA','Efectivo'=>'EFECTIVO','Tarjeta'=>'TARJETA'];
        $metodoEnum = $mapMetodo[$metodo] ?? 'TRANSFERENCIA';

        $pdo->prepare('UPDATE PAGOS SET comprobante = ?, referencia = ?, metodo = ?, fecha_pago = NOW() WHERE idPago = ? AND idCliente = ?')
            ->execute([$comprobante, $referencia, $metodoEnum, $idPago, $auth['id']]);
        lsLog($pdo, 'PAYMENT_UPLOAD', "Comprobante subido: $idPago — Ref: $referencia — Metodo: $metodo", null, null, $auth['id']);
        @lsNotifyAdmin('🧾 Comprobante recibido — ' . $idPago, lsMailTable([
            lsRow('Orden', $idPago),
            lsRow('Método', $metodo ?: 'Transferencia'),
            lsRow('Referencia', $referencia ?: '—'),
            lsRow('Cliente', $auth['id']),
        ]) . '<p style="font-size:13px;color:#94A3B8">Revisa y aprueba el pago en el panel de administración.</p>');
        lsJsonOutput(['exito' => true, 'mensaje' => 'Comprobante enviado. Será verificado en breve.']);
    }

    /* ============ CLIENTE: ver mis pagos ============ */
    if ($action === 'client_list') {
        $auth = lsRequireAuth('client');
        $stmt = $pdo->prepare('SELECT p.*, l.clave_activacion, l.plan, l.estado as lic_estado, pl.nombre as plan_nombre
            FROM PAGOS p
            LEFT JOIN LICENCIAS l ON p.idLicencia = l.idLicencia
            LEFT JOIN PLANES_LICENCIA pl ON p.idPlan = pl.idPlan
            WHERE p.idCliente = ? ORDER BY p.created_at DESC');
        $stmt->execute([$auth['id']]);
        $pagos = $stmt->fetchAll();
        foreach ($pagos as &$p) {
            $p['id'] = $p['idPago'];
            $p['plan_nombre'] = $p['plan_nombre'] ?? $p['plan'];
            $p['metodo_pago'] = $p['metodo'];
        }
        unset($p);
        lsJsonOutput(['exito' => true, 'pagos' => $pagos]);
    }

    lsJsonOutput(['exito' => false, 'mensaje' => 'Acción no válida.']);

} catch (Exception $e) {
    error_log('[Comandix][payments] ' . $e->getMessage());
    lsJsonOutput(['exito' => false, 'mensaje' => 'Ocurrio un error procesando el pago.'], 500);
}

/* ------------------------------------------------------------
   Marca un pago como APROBADO y activa su licencia asociada.
   ------------------------------------------------------------ */
function lsActivarPago($pdo, $idPago, $idCliente = null, $metodo = null, $gatewayRef = null, $idAdmin = null, $notas = 'Pago confirmado') {
    $stmt = $pdo->prepare('SELECT p.*, l.idLicencia, l.duracion_meses FROM PAGOS p LEFT JOIN LICENCIAS l ON p.idLicencia = l.idLicencia WHERE p.idPago = ?');
    $stmt->execute([$idPago]);
    $pago = $stmt->fetch();
    if (!$pago) return false;
    if ($pago['estado'] === 'APROBADO') return true; // idempotente

    $sql = 'UPDATE PAGOS SET estado = "APROBADO", fecha_pago = COALESCE(fecha_pago, NOW()), fecha_verificacion = NOW(), notas = ?';
    $params = [$notas];
    if ($metodo)     { $sql .= ', metodo = ?';     $params[] = $metodo; }
    if ($gatewayRef) { $sql .= ', gateway_ref = ?'; $params[] = $gatewayRef; }
    if ($idAdmin)    { $sql .= ', verificado_por = ?'; $params[] = $idAdmin; }
    $sql .= ' WHERE idPago = ?';
    $params[] = $idPago;
    $pdo->prepare($sql)->execute($params);

    if ($pago['idLicencia']) {
        $meses = intval($pago['duracion_meses'] ?? 12);
        if ($meses < 1) $meses = 12;
        $pdo->prepare('UPDATE LICENCIAS SET estado = "ACTIVA", fecha_activacion = NOW(), fecha_expiracion = DATE_ADD(NOW(), INTERVAL ? MONTH) WHERE idLicencia = ? AND estado = "PENDIENTE"')
            ->execute([$meses, $pago['idLicencia']]);
    }
    lsLog($pdo, 'PAYMENT_APPROVE', "Pago aprobado: $idPago — Metodo: " . ($metodo ?? 'manual'), $pago['idLicencia'], $idAdmin, $idCliente);

    // Correo: notificar aprobación (admin + cliente si tiene email)
    $rows = [
        lsRow('Orden', $idPago),
        lsRow('Estado', 'APROBADO'),
        lsRow('Método', $metodo ?? 'manual'),
    ];
    if ($gatewayRef) $rows[] = lsRow('Ref. pasarela', $gatewayRef);
    $cuerpo = '<p style="font-size:14px">✅ El pago fue <strong>aprobado</strong> y la licencia quedó activa.</p>' . lsMailTable($rows);
    @lsNotifyAdmin('✅ Pago aprobado — ' . $idPago, $cuerpo);
    // Email al cliente
    $cli = $pdo->prepare('SELECT email, nombre FROM CLIENTES WHERE idCliente = ?');
    $cli->execute([$pago['idCliente']]);
    $c = $cli->fetch();
    if ($c && !empty($c['email'])) {
        @lsSendMail($c['email'], '✅ Tu licencia Comandix está activa',
            '<p style="font-size:14px">Hola ' . htmlspecialchars($c['nombre'] ?? '') . ', tu pago fue confirmado y tu licencia ya está <strong>activa</strong>. ¡Gracias por tu compra!</p>' . lsMailTable($rows));
    }
    return true;
}
