<?php
/* ============================================
   LICENSE SERVER — License Management API
   Admin: CRUD licenses, stats
   Client: view own, purchase
   External: verify + activate for POS
   ============================================ */

require_once 'config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $pdo = lsGetConnection();
    list($action, $input) = lsGetAction();

    // ========== ADMIN: List all licenses ==========
    if ($action === 'admin_list') {
        $auth = lsRequireAuth('admin');
        $page = max(1, intval($input['page'] ?? $_GET['page'] ?? 1));
        $limit = min(100, max(10, intval($input['limit'] ?? $_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = trim($input['search'] ?? $_GET['search'] ?? '');
        $estado = trim($input['estado'] ?? $_GET['estado'] ?? '');
        $plan = trim($input['plan'] ?? $_GET['plan'] ?? '');

        $where = [];
        $params = [];
        if ($search) { $where[] = '(l.idLicencia LIKE ? OR l.clave_activacion LIKE ? OR l.negocio_nombre LIKE ? OR l.contacto_email LIKE ? OR c.nombre LIKE ? OR c.empresa LIKE ?)'; $p = "%$search%"; $params[] = $p; $params[] = $p; $params[] = $p; $params[] = $p; $params[] = $p; $params[] = $p; }
        if ($estado) { $where[] = 'l.estado = ?'; $params[] = $estado; }
        if ($plan) { $where[] = 'l.plan = ?'; $params[] = $plan; }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $pdo->prepare("SELECT COUNT(*) FROM LICENCIAS l LEFT JOIN CLIENTES c ON l.idCliente = c.idCliente $whereSQL");
        $total->execute($params);
        $totalRows = intval($total->fetchColumn());

        $stmt = $pdo->prepare("SELECT l.*, c.nombre as cliente_nombre, c.empresa, c.email as cliente_email, p.nombre as plan_nombre
            FROM LICENCIAS l
            LEFT JOIN CLIENTES c ON l.idCliente = c.idCliente
            LEFT JOIN PLANES_LICENCIA p ON l.idPlan = p.idPlan
            $whereSQL ORDER BY l.created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $licenses = $stmt->fetchAll();

        lsJsonOutput([
            'exito' => true,
            'licencias' => $licenses,
            'total' => $totalRows,
            'page' => $page,
            'pages' => ceil($totalRows / $limit)
        ]);
    }

    // ========== ADMIN: Get single license by ID ==========
    if ($action === 'admin_get') {
        $auth = lsRequireAuth('admin');
        $id = intval($input['idLicencia'] ?? $_GET['idLicencia'] ?? 0);
        $stmt = $pdo->prepare("SELECT l.*, c.nombre as cliente_nombre, c.empresa, c.email as cliente_email, p.nombre as plan_nombre
            FROM LICENCIAS l
            LEFT JOIN CLIENTES c ON l.idCliente = c.idCliente
            LEFT JOIN PLANES_LICENCIA p ON l.idPlan = p.idPlan
            WHERE l.idLicencia = ? LIMIT 1");
        $stmt->execute([$id]);
        $lic = $stmt->fetch();
        if (!$lic) { lsJsonOutput(['exito' => false, 'mensaje' => 'Licencia no encontrada']); }
        lsJsonOutput(['exito' => true, 'licencia' => $lic]);
    }

    // ========== ADMIN: List clients (para selector de licencias) ==========
    if ($action === 'admin_clients') {
        $auth = lsRequireAuth('admin');
        $stmt = $pdo->query('SELECT idCliente, nombre, apellido, email, empresa, telefono, ciudad, fechaRegistro,
            (SELECT COUNT(*) FROM LICENCIAS l WHERE l.idCliente = c.idCliente) AS total_licencias
            FROM CLIENTES c ORDER BY c.fechaRegistro DESC');
        lsJsonOutput(['exito' => true, 'clientes' => $stmt->fetchAll()]);
    }

    // ========== ADMIN: Create client (rápido, desde el panel) ==========
    if ($action === 'admin_create_client') {
        $auth = lsRequireAuth('admin');
        $nombre = trim($input['nombre'] ?? '');
        $email = trim($input['email'] ?? '');
        $telefono = trim($input['telefono'] ?? '');
        $empresa = trim($input['empresa'] ?? '');
        $ciudad = trim($input['ciudad'] ?? '');
        if (empty($nombre) || empty($email))
            lsJsonOutput(['exito' => false, 'mensaje' => 'Nombre y email del cliente son obligatorios.']);

        // ¿Ya existe?
        $chk = $pdo->prepare('SELECT idCliente FROM CLIENTES WHERE email = ?');
        $chk->execute([$email]);
        if ($chk->fetchColumn())
            lsJsonOutput(['exito' => false, 'mensaje' => 'Ya existe un cliente con ese email.']);

        $idCli = lsGenerarId('CLI-');
        $pass = password_hash('cliente' . rand(1000, 9999), PASSWORD_BCRYPT);
        $pdo->prepare('INSERT INTO CLIENTES (idCliente, nombre, email, telefono, empresa, ciudad, clave, estado, fechaRegistro)
            VALUES (?, ?, ?, ?, ?, ?, ?, "ACTIVO", NOW())')
            ->execute([$idCli, $nombre, $email, $telefono ?: null, $empresa ?: null, $ciudad ?: null, $pass]);
        lsLog($pdo, 'CLIENT_CREATE', "Cliente creado por admin: $nombre <$email>", null, $auth['id'], $idCli);
        lsJsonOutput(['exito' => true, 'mensaje' => 'Cliente creado.', 'cliente' => ['idCliente' => $idCli, 'nombre' => $nombre, 'email' => $email]]);
    }

    // ========== ADMIN: Create license ==========
    if ($action === 'admin_create') {
        $auth = lsRequireAuth('admin');
        $idCliente = trim($input['idCliente'] ?? $input['cliente_id'] ?? '');
        $planId = trim($input['idPlan'] ?? $input['plan_id'] ?? 'PLAN-BAS');
        $negocio = trim($input['negocio_nombre'] ?? '');
        $duracion = intval($input['duracion_meses'] ?? $input['duracion_dias'] ?? 12);
        $maxDevices = intval($input['max_activaciones'] ?? $input['dispositivos_maximos'] ?? 1);
        $modulos = $input['modulos'] ?? [];
        $notas = trim($input['notas'] ?? '');

        if (empty($idCliente) || empty($negocio))
            lsJsonOutput(['exito' => false, 'mensaje' => 'Cliente y nombre del negocio son obligatorios.']);

        // Derivar el enum de plan A PARTIR del idPlan seleccionado (antes quedaba siempre BASICO).
        $planEnumMap = ['PLAN-BAS' => 'BASICO', 'PLAN-PRO' => 'PROFESIONAL', 'PLAN-ENT' => 'ENTERPRISE'];
        if (empty($planId) || !isset($planEnumMap[$planId])) $planId = 'PLAN-BAS';
        $plan = $planEnumMap[$planId];

        // Verificar que el cliente exista (evita el error de clave foránea silencioso)
        $chk = $pdo->prepare('SELECT idCliente FROM CLIENTES WHERE idCliente = ?');
        $chk->execute([$idCliente]);
        if (!$chk->fetchColumn())
            lsJsonOutput(['exito' => false, 'mensaje' => 'El cliente seleccionado no existe. Refresca la lista de clientes.']);

        $idLic = lsGenerarId('LIC-');
        $clave = lsGenerarClave();

        // Tomar límites y módulos del plan seleccionado
        $stmt = $pdo->prepare('SELECT max_usuarios, max_mesas, modulos FROM PLANES_LICENCIA WHERE idPlan = ?');
        $stmt->execute([$planId]);
        $planData = $stmt->fetch() ?: [];
        $maxUsuarios = intval($input['max_usuarios'] ?? ($planData['max_usuarios'] ?? 5));
        $maxMesas = intval($input['max_mesas'] ?? ($planData['max_mesas'] ?? 20));
        if (empty($modulos)) {
            $modulos = isset($planData['modulos']) ? json_decode($planData['modulos'] ?? '[]', true) : [];
        }

        $pdo->prepare('INSERT INTO LICENCIAS 
            (idLicencia, clave_activacion, idCliente, idPlan, negocio_nombre, 
             plan, duracion_meses, fecha_activacion, fecha_expiracion, estado, 
             max_usuarios, max_mesas, modulos_habilitados, activaciones, max_activaciones, motivo_estado) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH), "ACTIVA", ?, ?, ?, 0, ?, ?)')
            ->execute([
                $idLic, $clave, $idCliente, $planId, $negocio,
                $plan, $duracion, $duracion,
                $maxUsuarios, $maxMesas, json_encode($modulos), $maxDevices, $notas
            ]);

        lsLog($pdo, 'LICENSE_CREATE', "Licencia creada: $clave — Plan: $plan — Negocio: $negocio", $idLic, $auth['id']);

        // ===== Multi-tenant: aprovisionar la instancia POS del cliente =====
        require_once __DIR__ . '/provisioner.php';
        $prov = provisionTenant($idLic, $clave);
        $urlInstancia = null;
        if ($prov['exito']) {
            registrarTenantEnLicencia($pdo, $idLic, $prov);
            $urlInstancia = $prov['url'];
            lsLog($pdo, 'TENANT_PROVISION', 'Instancia POS creada: ' . $prov['dbName'], $idLic, $auth['id']);
        } else {
            lsLog($pdo, 'TENANT_PROVISION_FAIL', $prov['mensaje'], $idLic, $auth['id']);
        }

        lsJsonOutput([
            'exito' => true,
            'mensaje' => 'Licencia creada exitosamente.' . ($prov['exito'] ? '' : ' (La instancia POS se aprovisionara luego: ' . $prov['mensaje'] . ')'),
            'clave_licencia' => $clave,
            'url_instancia' => $urlInstancia,
            'credenciales_iniciales' => ($prov['exito'] ? [
                'usuario' => $prov['tenantAdminUser'],
                'contrasena' => $prov['tenantAdminPassword'],
                'nota' => 'Mostrar al cliente una sola vez y solicitar cambio inmediato.'
            ] : null),
            'licencia' => ['id' => $idLic, 'clave' => $clave, 'plan' => $plan, 'negocio' => $negocio]
        ]);
    }

    // ========== ADMIN: Update license ==========
    if ($action === 'admin_update') {
        $auth = lsRequireAuth('admin');
        $idLic = trim($input['idLicencia'] ?? $input['licencia_id'] ?? '');
        if (empty($idLic)) lsJsonOutput(['exito' => false, 'mensaje' => 'ID de licencia requerido.']);

        $fields = [];
        $params = [];
        $allowed = ['negocio_nombre','contacto_email','contacto_telefono','max_usuarios','max_mesas','max_activaciones','estado','motivo_estado'];
        foreach ($allowed as $f) {
            if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = $input[$f]; }
        }
        if (isset($input['modulos']) || isset($input['modulos_habilitados'])) {
            $fields[] = 'modulos_habilitados = ?';
            $params[] = json_encode($input['modulos'] ?? $input['modulos_habilitados']);
        }
        if (isset($input['fecha_expiracion'])) { $fields[] = 'fecha_expiracion = ?'; $params[] = $input['fecha_expiracion']; }
        if (isset($input['dispositivos_maximos'])) { $fields[] = 'max_activaciones = ?'; $params[] = intval($input['dispositivos_maximos']); }
        if (isset($input['notas'])) { $fields[] = 'motivo_estado = ?'; $params[] = $input['notas']; }

        if (empty($fields)) lsJsonOutput(['exito' => false, 'mensaje' => 'Nada que actualizar.']);

        $params[] = $idLic;
        $pdo->prepare('UPDATE LICENCIAS SET ' . implode(', ', $fields) . ' WHERE idLicencia = ?')->execute($params);

        lsLog($pdo, 'LICENSE_UPDATE', 'Licencia actualizada: ' . implode(', ', $fields), $idLic, $auth['id']);
        lsJsonOutput(['exito' => true, 'mensaje' => 'Licencia actualizada.']);
    }

    // ========== ADMIN: Aprovisionar / reintentar instancia POS ==========
    // Crea (o repara) la base de datos y la copia del sistema POS de una licencia.
    // Idempotente: si la BD/usuario/carpeta ya existen no los duplica.
    if ($action === 'admin_provision') {
        $auth = lsRequireAuth('admin');
        $idLic = trim($input['idLicencia'] ?? $input['licencia_id'] ?? '');
        if (empty($idLic)) lsJsonOutput(['exito' => false, 'mensaje' => 'ID de licencia requerido.']);

        $stmt = $pdo->prepare('SELECT idLicencia, clave_activacion FROM LICENCIAS WHERE idLicencia = ? LIMIT 1');
        $stmt->execute([$idLic]);
        $lic = $stmt->fetch();
        if (!$lic) lsJsonOutput(['exito' => false, 'mensaje' => 'La licencia no existe.']);

        require_once __DIR__ . '/provisioner.php';
        $prov = provisionTenant($idLic, $lic['clave_activacion'] ?? $idLic);
        if (!$prov['exito']) {
            lsLog($pdo, 'TENANT_PROVISION_FAIL', $prov['mensaje'], $idLic, $auth['id']);
            lsJsonOutput(['exito' => false, 'mensaje' => 'No se pudo aprovisionar la instancia: ' . $prov['mensaje']]);
        }
        registrarTenantEnLicencia($pdo, $idLic, $prov);
        lsLog($pdo, 'TENANT_PROVISION', 'Instancia POS aprovisionada/reparada: ' . $prov['dbName'], $idLic, $auth['id']);
        lsJsonOutput([
            'exito' => true,
            'mensaje' => 'Instancia POS lista.',
            'url_instancia' => $prov['url'],
            'db_instancia' => $prov['dbName'],
            'credenciales_iniciales' => [
                'usuario' => $prov['tenantAdminUser'],
                'contrasena' => $prov['tenantAdminPassword'],
                'nota' => 'Se generan credenciales nuevas al reaprovisionar. Muestralas una sola vez.'
            ]
        ]);
    }

    // ========== ADMIN: Estado de instancia POS de una licencia ==========
    if ($action === 'admin_instance') {
        $auth = lsRequireAuth('admin');
        $idLic = trim($input['idLicencia'] ?? $_GET['idLicencia'] ?? '');
        if (empty($idLic)) lsJsonOutput(['exito' => false, 'mensaje' => 'ID de licencia requerido.']);
        $row = null;
        try {
            $stmt = $pdo->prepare('SELECT idTenant, idLicencia, db_name, db_host, base_url, estado, provisioned_at, actualizado FROM TENANTS WHERE idLicencia = ? LIMIT 1');
            $stmt->execute([$idLic]);
            $row = $stmt->fetch();
        } catch (Throwable $e) { $row = null; }
        lsJsonOutput([
            'exito' => true,
            'aprovisionada' => $row ? true : false,
            'instancia' => $row ?: null
        ]);
    }

    // ========== ADMIN: Stats ==========
    if ($action === 'admin_stats') {
        $auth = lsRequireAuth('admin');

        $s = [];
        $s['total_licencias'] = $pdo->query('SELECT COUNT(*) FROM LICENCIAS')->fetchColumn();
        $s['activas'] = $pdo->query('SELECT COUNT(*) FROM LICENCIAS WHERE estado = "ACTIVA"')->fetchColumn();
        $s['pendientes'] = $pdo->query('SELECT COUNT(*) FROM LICENCIAS WHERE estado = "PENDIENTE"')->fetchColumn();
        $s['suspendidas'] = $pdo->query('SELECT COUNT(*) FROM LICENCIAS WHERE estado = "SUSPENDIDA"')->fetchColumn();
        $s['expiradas'] = $pdo->query('SELECT COUNT(*) FROM LICENCIAS WHERE estado = "EXPIRADA"')->fetchColumn();
        $s['revocadas'] = $pdo->query('SELECT COUNT(*) FROM LICENCIAS WHERE estado = "REVOCADA"')->fetchColumn();
        $s['prueba'] = $pdo->query('SELECT COUNT(*) FROM LICENCIAS WHERE estado = "PRUEBA"')->fetchColumn();
        $s['total_clientes'] = $pdo->query('SELECT COUNT(*) FROM CLIENTES')->fetchColumn();
        $s['total_activaciones'] = $pdo->query('SELECT COUNT(*) FROM LICENCIA_ACTIVACIONES')->fetchColumn();

        // Revenue
        $s['ingresos_mes'] = $pdo->query('SELECT COALESCE(SUM(monto), 0) FROM PAGOS WHERE estado = "APROBADO" AND MONTH(fecha_verificacion) = MONTH(NOW()) AND YEAR(fecha_verificacion) = YEAR(NOW())')->fetchColumn();
        $s['pagos_pendientes'] = $pdo->query('SELECT COUNT(*) FROM PAGOS WHERE estado = "PENDIENTE"')->fetchColumn();

        // Plan breakdown
        $stmt = $pdo->query('SELECT plan, COUNT(*) as cnt FROM LICENCIAS GROUP BY plan');
        $s['por_plan'] = [];
        while ($r = $stmt->fetch()) $s['por_plan'][$r['plan']] = intval($r['cnt']);

        lsJsonOutput(['exito' => true, 'estadisticas' => $s]);
    }

    // ========== CLIENT: View own licenses ==========
    if ($action === 'client_licenses') {
        $auth = lsRequireAuth('client');
        $stmt = $pdo->prepare('SELECT l.*, p.nombre as plan_nombre, p.color as plan_color, p.icono as plan_icono
            FROM LICENCIAS l
            LEFT JOIN PLANES_LICENCIA p ON l.idPlan = p.idPlan
            WHERE l.idCliente = ? ORDER BY l.created_at DESC');
        $stmt->execute([$auth['id']]);
        $licencias = $stmt->fetchAll();
        
        // Map clave_activacion to clave_licencia for frontend compatibility
        foreach ($licencias as &$lic) {
            $lic['clave_licencia'] = $lic['clave_activacion'];
            $lic['plan_nombre'] = $lic['plan_nombre'] ?? $lic['plan'];
            $lic['activaciones_usadas'] = intval($lic['activaciones']);
            $lic['dispositivos_maximos'] = intval($lic['max_activaciones']);
            $lic['fecha_expiracion'] = $lic['fecha_expiracion'] ?? '—';
        }
        unset($lic);
        
        lsJsonOutput(['exito' => true, 'licencias' => $licencias]);
    }

    // ========== CLIENT: Solicitar reinicio de licencia (2FA por correo) ==========
    if ($action === 'client_reset_request') {
        $auth = lsRequireAuth('client');
        $idLic = trim($input['idLicencia'] ?? $input['licencia_id'] ?? '');
        if ($idLic === '') lsJsonOutput(['exito' => false, 'mensaje' => 'Falta el identificador de la licencia.']);

        lsEnsureResetSchema($pdo);

        $stmt = $pdo->prepare('SELECT l.*, c.email AS cliente_email, c.nombre AS cliente_nombre
            FROM LICENCIAS l LEFT JOIN CLIENTES c ON l.idCliente = c.idCliente
            WHERE l.idLicencia = ? AND l.idCliente = ? LIMIT 1');
        $stmt->execute([$idLic, $auth['id']]);
        $lic = $stmt->fetch();
        if (!$lic) lsJsonOutput(['exito' => false, 'mensaje' => 'Licencia no encontrada.']);

        if (!in_array($lic['estado'], ['ACTIVA', 'PRUEBA'], true)) {
            lsJsonOutput(['exito' => false, 'mensaje' => 'Solo puedes resetear licencias activas o en prueba.']);
        }

        $email = trim($lic['cliente_email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            lsJsonOutput(['exito' => false, 'mensaje' => 'No hay un correo válido asociado a tu cuenta. Contacta a soporte.']);
        }

        // Cancelar solicitudes previas pendientes de esta licencia (una a la vez)
        $pdo->prepare("UPDATE LICENCIA_RESETS SET estado = 'CANCELADO' WHERE idLicencia = ? AND estado = 'PENDIENTE'")->execute([$idLic]);

        // Token de un solo uso: en la BD guardamos SOLO su hash
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = lsHashToken('reset:' . $rawToken);
        $expiraMin = 30;

        $pdo->prepare('INSERT INTO LICENCIA_RESETS (idLicencia, idCliente, token_hash, estado, clave_anterior, expira, creado, ip)
            VALUES (?, ?, ?, "PENDIENTE", ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW(), ?)')
            ->execute([$idLic, $auth['id'], $tokenHash, $lic['clave_activacion'], $expiraMin, $_SERVER['REMOTE_ADDR'] ?? '']);

        // Enlace de confirmación profesional (URL limpia -> api/reset)
        $base = rtrim(defined('LS_BASE_URL') ? LS_BASE_URL : '', '/');
        $link = $base . '/api/reset?token=' . $rawToken;

        require_once __DIR__ . '/mailer.php';
        $nombre      = htmlspecialchars($lic['cliente_nombre'] ?: 'Cliente');
        $claveMasked = substr($lic['clave_activacion'], 0, 4) . '-••••-••••-••••';
        $html = '<h2 style="color:#fff;margin:0 0 12px;font-size:18px">Confirma el reinicio de tu licencia</h2>'
            . '<p style="color:#cbd5e1;font-size:14px;line-height:1.6;margin:0 0 16px">Hola ' . $nombre . ', recibimos una solicitud para <strong>resetear la clave de activación</strong> de tu licencia <strong>' . htmlspecialchars($claveMasked) . '</strong>.</p>'
            . '<p style="color:#cbd5e1;font-size:14px;line-height:1.6;margin:0 0 8px">Al confirmar:</p>'
            . '<ul style="color:#cbd5e1;font-size:14px;line-height:1.7;margin:0 0 20px;padding-left:18px">'
            . '<li>Se generará una <strong>nueva clave de activación</strong>.</li>'
            . '<li>Se cerrarán los dispositivos que la usaban (útil si alguien más tuvo acceso).</li>'
            . '<li>Tu <strong>fecha de activación y expiración NO cambian</strong>.</li></ul>'
            . '<div style="text-align:center;margin:26px 0">'
            . '<a href="' . htmlspecialchars($link) . '" style="display:inline-block;background:linear-gradient(135deg,#6C3CE1,#E94560);color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:14px 34px;border-radius:12px">Confirmar reinicio de licencia</a></div>'
            . '<p style="color:#94A3B8;font-size:12px;line-height:1.6;margin:0">Este enlace vence en ' . $expiraMin . ' minutos y solo puede usarse una vez. Si no solicitaste este cambio, ignora este correo: tu licencia seguirá igual.</p>';

        list($ok, $err) = lsSendMail($email, 'Confirma el reinicio de tu licencia — Comandix', $html);

        lsLog($pdo, 'LICENSE_RESET_REQUEST', 'Solicitud de reinicio (2FA email) ' . ($ok ? 'enviada' : 'NO enviada: ' . $err), $idLic, null, $auth['id']);

        if (!$ok) {
            lsJsonOutput(['exito' => false, 'mensaje' => 'No pudimos enviar el correo de confirmación. Verifica tu correo o contacta a soporte.']);
        }

        lsJsonOutput(['exito' => true, 'mensaje' => 'Te enviamos un correo de confirmación a ' . lsMaskEmail($email) . '. Haz clic en el enlace para completar el reinicio.']);
    }

    // ========== CLIENT: View plans (public) ==========
    if ($action === 'client_plans') {
        $stmt = $pdo->query('SELECT * FROM PLANES_LICENCIA WHERE activo = 1 ORDER BY orden');
        lsJsonOutput(['exito' => true, 'planes' => $stmt->fetchAll()]);
    }

    // ========== CLIENT: Purchase plan ==========
    if ($action === 'client_purchase') {
        $auth = lsRequireAuth('client');
        $idPlan = trim($input['idPlan'] ?? $input['plan_id'] ?? '');
        $periodo = trim($input['periodo'] ?? 'mensual');
        $duracion = $periodo === 'anual' ? 12 : intval($input['duracion_meses'] ?? 1);
        $metodo = trim($input['metodo'] ?? 'TRANSFERENCIA');
        $negocio = trim($input['negocio_nombre'] ?? $input['restaurante'] ?? '');

        if (empty($idPlan)) lsJsonOutput(['exito' => false, 'mensaje' => 'Selecciona un plan.']);

        $stmt = $pdo->prepare('SELECT * FROM PLANES_LICENCIA WHERE idPlan = ? AND activo = 1');
        $stmt->execute([$idPlan]);
        $plan = $stmt->fetch();
        if (!$plan) lsJsonOutput(['exito' => false, 'mensaje' => 'Plan no encontrado.']);

        $monto = $duracion >= 12 ? $plan['precio_anual'] : ($plan['precio_mensual'] * $duracion);

        $planEnum = ['PLAN-BAS' => 'BASICO', 'PLAN-PRO' => 'PROFESIONAL', 'PLAN-ENT' => 'ENTERPRISE'][$idPlan] ?? 'BASICO';

        // Create pending license
        $idLic = lsGenerarId('LIC-');
        $clave = lsGenerarClave();
        $pdo->prepare('INSERT INTO LICENCIAS 
            (idLicencia, clave_activacion, idCliente, idPlan, negocio_nombre, plan, duracion_meses, 
             estado, max_usuarios, max_mesas, modulos_habilitados, activaciones, max_activaciones) 
            VALUES (?, ?, ?, ?, ?, ?, ?, "PENDIENTE", ?, ?, ?, 0, ?)')
            ->execute([
                $idLic, $clave, $auth['id'], $idPlan, $negocio ?: 'Sin nombre', $planEnum, $duracion,
                $plan['max_usuarios'], $plan['max_mesas'], $plan['modulos'], $plan['max_sucursales']
            ]);

        // Create payment record
        $idPago = lsGenerarId('PAY-');
        $pdo->prepare('INSERT INTO PAGOS (idPago, idCliente, idLicencia, idPlan, monto, moneda, metodo, estado) 
            VALUES (?, ?, ?, ?, ?, "COP", ?, "PENDIENTE")')
            ->execute([$idPago, $auth['id'], $idLic, $idPlan, $monto, $metodo]);

        lsLog($pdo, 'PURCHASE_INIT', "Compra iniciada: $planEnum x$duracion meses — $" . number_format($monto, 0, ',', '.') . " COP — $metodo", $idLic, null, $auth['id']);

        lsJsonOutput([
            'exito' => true,
            'mensaje' => 'Solicitud de compra creada. Sube tu comprobante de pago para activar tu licencia.',
            'pago' => ['id' => $idPago, 'monto' => $monto, 'metodo' => $metodo, 'estado' => 'PENDIENTE'],
            'licencia' => ['id' => $idLic, 'clave' => $clave, 'estado' => 'PENDIENTE']
        ]);
    }

    // ========== EXTERNAL: Verify license (for POS) ==========
    if ($action === 'verify') {
        $key = trim($_GET['key'] ?? $input['key'] ?? '');

        if (empty($key)) lsJsonOutput(['estado' => 'INVALIDA', 'mensaje' => 'Clave no proporcionada.']);

        $stmt = $pdo->prepare('SELECT * FROM LICENCIAS WHERE clave_activacion = ? LIMIT 1');
        $stmt->execute([$key]);
        $lic = $stmt->fetch();

        if (!$lic) lsJsonOutput(['estado' => 'INVALIDA', 'mensaje' => 'Clave no encontrada.']);

        // Auto-expire check
        if ($lic['fecha_expiracion'] && strtotime($lic['fecha_expiracion']) < time()) {
            $pdo->prepare('UPDATE LICENCIAS SET estado = "EXPIRADA" WHERE idLicencia = ? AND estado = "ACTIVA"')->execute([$lic['idLicencia']]);
            lsJsonOutput(['estado' => 'EXPIRADA', 'mensaje' => 'Licencia expirada el ' . $lic['fecha_expiracion'] . '.']);
        }

        lsJsonOutput([
            'estado' => $lic['estado'],
            'mensaje' => $lic['estado'] === 'ACTIVA' ? 'Licencia válida.' : 'Licencia no activa: ' . $lic['estado'],
            'plan' => $lic['plan'],
            'negocio_nombre' => $lic['negocio_nombre'],
            'fecha_expiracion' => $lic['fecha_expiracion'],
            'max_usuarios' => intval($lic['max_usuarios']),
            'max_mesas' => intval($lic['max_mesas']),
            'modulos_habilitados' => json_decode($lic['modulos_habilitados'] ?? '[]', true)
        ]);
    }

    // ========== EXTERNAL: Activate license (for POS) ==========
    if ($action === 'activate') {
        $key = trim($input['key'] ?? '');
        $hardwareId = trim($input['hardware_id'] ?? '');

        if (empty($key)) lsJsonOutput(['exito' => false, 'mensaje' => 'Clave requerida.']);

        $stmt = $pdo->prepare('SELECT * FROM LICENCIAS WHERE clave_activacion = ? LIMIT 1');
        $stmt->execute([$key]);
        $lic = $stmt->fetch();

        if (!$lic) lsJsonOutput(['exito' => false, 'mensaje' => 'Clave no encontrada.']);
        if ($lic['estado'] === 'REVOCADA') lsJsonOutput(['exito' => false, 'mensaje' => 'Licencia revocada.']);
        if ($lic['estado'] === 'EXPIRADA') lsJsonOutput(['exito' => false, 'mensaje' => 'Licencia expirada.']);
        if ($lic['estado'] === 'PENDIENTE') lsJsonOutput(['exito' => false, 'mensaje' => 'Licencia pendiente de pago.']);

        // Check activation limit
        $activaciones = intval($lic['activaciones']);
        $maxAct = intval($lic['max_activaciones']);
        if ($activaciones >= $maxAct && !empty($hardwareId)) {
            $stmt2 = $pdo->prepare('SELECT id FROM LICENCIA_ACTIVACIONES WHERE idLicencia = ? AND hardware_id = ? AND estado = "ACTIVA"');
            $stmt2->execute([$lic['idLicencia'], $hardwareId]);
            if (!$stmt2->fetch()) {
                lsJsonOutput(['exito' => false, 'mensaje' => 'Límite de activaciones alcanzado (' . $maxAct . '). Contacta soporte.']);
            }
        }

        // Record activation
        if (!empty($hardwareId)) {
            $pdo->prepare('INSERT INTO LICENCIA_ACTIVACIONES (idLicencia, hardware_id, hostname, platform, ip_address, activated_at, last_check, estado) 
                VALUES (?, ?, ?, ?, ?, NOW(), NOW(), "ACTIVA") 
                ON DUPLICATE KEY UPDATE last_check = NOW(), estado = "ACTIVA"')
                ->execute([
                    $lic['idLicencia'], $hardwareId,
                    $input['hostname'] ?? null, $input['platform'] ?? null,
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);

            $pdo->prepare('UPDATE LICENCIAS SET activaciones = activaciones + 1, hardware_id = ? WHERE idLicencia = ?')
                ->execute([$hardwareId, $lic['idLicencia']]);
        }

        lsLog($pdo, 'REMOTE_ACTIVATE', 'Activación remota: ' . ($hardwareId ?: 'sin HW ID'), $lic['idLicencia']);

        lsJsonOutput([
            'exito' => true,
            'mensaje' => 'Licencia activada en este dispositivo.',
            'licencia' => [
                'id' => $lic['idLicencia'],
                'plan' => $lic['plan'],
                'negocio_nombre' => $lic['negocio_nombre'],
                'fecha_activacion' => $lic['fecha_activacion'],
                'fecha_expiracion' => $lic['fecha_expiracion'],
                'max_usuarios' => intval($lic['max_usuarios']),
                'max_mesas' => intval($lic['max_mesas']),
                'modulos_habilitados' => json_decode($lic['modulos_habilitados'] ?? '[]', true)
            ]
        ]);
    }

    // Fallback
    lsJsonOutput(['exito' => false, 'mensaje' => 'Acción no válida.']);

} catch (Exception $e) {
    error_log('[Comandix][licenses] ' . $e->getMessage());
    lsJsonOutput(['exito' => false, 'mensaje' => 'Ocurrio un error procesando la solicitud.'], 500);
}

/* ------------------------------------------------------------
   Helpers para el reinicio de licencia (2FA por correo)
   ------------------------------------------------------------ */
function lsEnsureResetSchema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS LICENCIA_RESETS (
        id INT AUTO_INCREMENT PRIMARY KEY,
        idLicencia VARCHAR(40) NOT NULL,
        idCliente VARCHAR(40) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        estado ENUM('PENDIENTE','CONFIRMADO','CANCELADO') NOT NULL DEFAULT 'PENDIENTE',
        clave_anterior VARCHAR(60) DEFAULT NULL,
        clave_nueva VARCHAR(60) DEFAULT NULL,
        expira DATETIME NOT NULL,
        creado DATETIME NOT NULL,
        confirmado DATETIME DEFAULT NULL,
        ip VARCHAR(64) DEFAULT NULL,
        UNIQUE KEY uq_reset_token (token_hash),
        KEY idx_reset_lic (idLicencia)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function lsMaskEmail($email) {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    $u = $parts[0];
    $masked = strlen($u) <= 2
        ? substr($u, 0, 1) . '***'
        : substr($u, 0, 2) . str_repeat('*', max(1, strlen($u) - 2));
    return $masked . '@' . $parts[1];
}