<?php
/* ============================================
   LICENSE SERVER — Database Setup
   Creates: freakers_licenses DB + all tables
   Seeds: admin account, license plans, demo data
   
   Run: http://localhost/license-server/api/setup.php
   ============================================ */

header('Content-Type: text/html; charset=utf-8');

// Bloqueo de seguridad: exige localhost + SETUP_TOKEN + candado de instalacion.
require_once __DIR__ . '/_setup_guard.php';

$steps = [];
$errors = [];

try {
    require_once __DIR__ . '/../config/config.php';
    $setupDbUser = envValue('SETUP_DB_USER', LS_DB_USER);
    $setupDbPass = envValue('SETUP_DB_PASS', LS_DB_PASS);
    $setupDbName = preg_replace('/[^A-Za-z0-9_]/', '', envValue('LS_DB_NAME', 'freakers_licenses'));
    if ($setupDbUser === '') throw new RuntimeException('Configure SETUP_DB_USER/SETUP_DB_PASS en .env.');
    $pdo = new PDO(
        'mysql:host=' . LS_DB_HOST . ';charset=utf8mb4',
        $setupDbUser,
        $setupDbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $steps[] = 'Conexion a MySQL exitosa';

    // Create database
    $pdo->exec("DROP DATABASE IF EXISTS `$setupDbName`");
    $pdo->exec("CREATE DATABASE `$setupDbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$setupDbName`");
    $pdo->exec("SET time_zone = '-05:00'");
    $steps[] = 'Base de datos freakers_licenses creada';

    // ===== 1. ADMIN_USUARIOS =====
    $pdo->exec('CREATE TABLE ADMIN_USUARIOS (
        idAdmin VARCHAR(50) PRIMARY KEY,
        usuario VARCHAR(80) NOT NULL UNIQUE,
        clave VARCHAR(255) NOT NULL,
        nombre VARCHAR(120) NOT NULL,
        email VARCHAR(200) DEFAULT NULL,
        rol ENUM("SUPER","ADMIN","SOPORTE") NOT NULL DEFAULT "ADMIN",
        estado ENUM("ACTIVO","INACTIVO") NOT NULL DEFAULT "ACTIVO",
        fechaCreacion DATETIME DEFAULT CURRENT_TIMESTAMP,
        ultimoLogin DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $adminPassword = envValue('SETUP_ADMIN_PASSWORD', '');
    if ($adminPassword === '') $adminPassword = bin2hex(random_bytes(16));
    $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO ADMIN_USUARIOS VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NULL)')
        ->execute(['ADM-001', 'searpix', $hash, 'SearPix', 'searpix@gmail.com', 'SUPER', 'ACTIVO']);
    $steps[] = 'Tabla ADMIN_USUARIOS creada. La contraseña inicial se obtiene de SETUP_ADMIN_PASSWORD o se genera aleatoriamente; no se imprime en logs.';

    // ===== 2. CLIENTES =====
    $pdo->exec('CREATE TABLE CLIENTES (
        idCliente VARCHAR(50) PRIMARY KEY,
        nombre VARCHAR(200) NOT NULL,
        apellido VARCHAR(200) DEFAULT NULL,
        email VARCHAR(200) NOT NULL UNIQUE,
        telefono VARCHAR(50) DEFAULT NULL,
        empresa VARCHAR(200) DEFAULT NULL,
        nit VARCHAR(50) DEFAULT NULL,
        pais VARCHAR(100) DEFAULT "Colombia",
        ciudad VARCHAR(100) DEFAULT NULL,
        clave VARCHAR(255) NOT NULL,
        estado ENUM("ACTIVO","INACTIVO","SUSPENDIDO") NOT NULL DEFAULT "ACTIVO",
        fechaRegistro DATETIME DEFAULT CURRENT_TIMESTAMP,
        ultimoLogin DATETIME DEFAULT NULL,
        whatsapp VARCHAR(50) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla CLIENTES creada';

    // ===== 3. PLANES_LICENCIA =====
    $pdo->exec('CREATE TABLE PLANES_LICENCIA (
        idPlan VARCHAR(50) PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        descripcion TEXT DEFAULT NULL,
        precio_mensual DECIMAL(12,2) NOT NULL DEFAULT 0,
        precio_anual DECIMAL(12,2) NOT NULL DEFAULT 0,
        max_usuarios INT NOT NULL DEFAULT 5,
        max_mesas INT NOT NULL DEFAULT 20,
        max_sucursales INT NOT NULL DEFAULT 1,
        modulos JSON DEFAULT NULL,
        popular TINYINT(1) NOT NULL DEFAULT 0,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        orden INT NOT NULL DEFAULT 0,
        color VARCHAR(20) DEFAULT NULL,
        icono VARCHAR(50) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $planes = [
        [
            'PLAN-BAS', 'Básico', 'Ideal para restaurantes pequeños que inician su transformación digital. Incluye lo esencial para gestionar tu menú y ventas.',
            49900, 499900, 3, 15, 1,
            json_encode(['pos','categorias','productos','ordenes','pagos']),
            0, 1, 1, '#38BDF8', '🚀'
        ],
        [
            'PLAN-PRO', 'Profesional', 'Para restaurantes en crecimiento que necesitan domicilios, múltiples usuarios y control completo de su operación.',
            89900, 899900, 8, 40, 2,
            json_encode(['pos','domicilios','categorias','productos','ordenes','pagos','usuarios','dashboard','ticket_config','delivery_apps']),
            1, 1, 2, '#A855F7', '⚡'
        ],
        [
            'PLAN-ENT', 'Enterprise', 'Sin límites. Para cadenas y restaurantes que quieren todo: WhatsApp integrado, facturación, multi-sucursal y soporte prioritario.',
            149900, 1499900, 50, 200, 10,
            json_encode(['pos','domicilios','categorias','productos','ordenes','pagos','usuarios','movimientos','dashboard','configuracion','ticket_config','delivery_apps','whatsapp','facturacion','mesas_unidas','notificaciones','multi_idioma']),
            0, 1, 3, '#E94560', '👑'
        ]
    ];
    $stmt = $pdo->prepare('INSERT INTO PLANES_LICENCIA VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($planes as $p) $stmt->execute($p);
    $steps[] = 'Tabla PLANES_LICENCIA creada (3 planes: Básico, Profesional, Enterprise)';

    // ===== 4. LICENCIAS =====
    $pdo->exec('CREATE TABLE LICENCIAS (
        idLicencia VARCHAR(50) PRIMARY KEY,
        clave_activacion VARCHAR(255) NOT NULL UNIQUE,
        idCliente VARCHAR(50) NOT NULL,
        idPlan VARCHAR(50) NOT NULL,
        negocio_nombre VARCHAR(200) NOT NULL,
        negocio_nit VARCHAR(50) DEFAULT NULL,
        contacto_nombre VARCHAR(200) DEFAULT NULL,
        contacto_email VARCHAR(200) DEFAULT NULL,
        contacto_telefono VARCHAR(50) DEFAULT NULL,
        plan ENUM("BASICO","PROFESIONAL","ENTERPRISE") NOT NULL DEFAULT "BASICO",
        duracion_meses INT NOT NULL DEFAULT 12,
        fecha_activacion DATETIME DEFAULT NULL,
        fecha_expiracion DATETIME DEFAULT NULL,
        estado ENUM("ACTIVA","SUSPENDIDA","EXPIRADA","REVOCADA","PENDIENTE") NOT NULL DEFAULT "PENDIENTE",
        max_usuarios INT DEFAULT 5,
        max_mesas INT DEFAULT 20,
        modulos_habilitados JSON DEFAULT NULL,
        hardware_id VARCHAR(255) DEFAULT NULL,
        activaciones INT DEFAULT 0,
        max_activaciones INT DEFAULT 1,
        motivo_estado TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (idCliente) REFERENCES CLIENTES(idCliente) ON DELETE CASCADE,
        FOREIGN KEY (idPlan) REFERENCES PLANES_LICENCIA(idPlan)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla LICENCIAS creada';

    // ===== 5. LICENCIA_ACTIVACIONES (log de activaciones por dispositivo) =====
    $pdo->exec('CREATE TABLE LICENCIA_ACTIVACIONES (
        id INT AUTO_INCREMENT PRIMARY KEY,
        idLicencia VARCHAR(50) NOT NULL,
        hardware_id VARCHAR(255) NOT NULL,
        hostname VARCHAR(200) DEFAULT NULL,
        platform VARCHAR(100) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_check DATETIME DEFAULT NULL,
        estado ENUM("ACTIVA","REMOVIDA","BLOQUEADA") DEFAULT "ACTIVA",
        FOREIGN KEY (idLicencia) REFERENCES LICENCIAS(idLicencia) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla LICENCIA_ACTIVACIONES creada';

    // ===== 6. LICENCIA_LOG (audit trail completo) =====
    $pdo->exec('CREATE TABLE LICENCIA_LOG (
        id INT AUTO_INCREMENT PRIMARY KEY,
        idLicencia VARCHAR(50) DEFAULT NULL,
        idAdmin VARCHAR(50) DEFAULT NULL,
        idCliente VARCHAR(50) DEFAULT NULL,
        accion VARCHAR(100) NOT NULL,
        descripcion TEXT DEFAULT NULL,
        ip VARCHAR(45) DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (idLicencia) REFERENCES LICENCIAS(idLicencia) ON DELETE SET NULL,
        FOREIGN KEY (idAdmin) REFERENCES ADMIN_USUARIOS(idAdmin) ON DELETE SET NULL,
        FOREIGN KEY (idCliente) REFERENCES CLIENTES(idCliente) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla LICENCIA_LOG creada (audit trail)';

    // ===== 7. PAGOS =====
    $pdo->exec('CREATE TABLE PAGOS (
        idPago VARCHAR(50) PRIMARY KEY,
        idCliente VARCHAR(50) NOT NULL,
        idLicencia VARCHAR(50) DEFAULT NULL,
        idPlan VARCHAR(50) DEFAULT NULL,
        monto DECIMAL(12,2) NOT NULL,
        moneda VARCHAR(10) DEFAULT "COP",
        metodo ENUM("TRANSFERENCIA","NEQUI","DAVIPLATA","TARJETA","EFECTIVO","WOMPI","PAYU","PAYPAL") DEFAULT "TRANSFERENCIA",
        referencia VARCHAR(200) DEFAULT NULL,
        gateway_ref VARCHAR(255) DEFAULT NULL,
        comprobante TEXT DEFAULT NULL,
        estado ENUM("PENDIENTE","APROBADO","RECHAZADO","REEMBOLSADO") DEFAULT "PENDIENTE",
        fecha_pago DATETIME DEFAULT NULL,
        fecha_verificacion DATETIME DEFAULT NULL,
        verificado_por VARCHAR(50) DEFAULT NULL,
        notas TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (idCliente) REFERENCES CLIENTES(idCliente) ON DELETE CASCADE,
        FOREIGN KEY (idLicencia) REFERENCES LICENCIAS(idLicencia) ON DELETE SET NULL,
        FOREIGN KEY (idPlan) REFERENCES PLANES_LICENCIA(idPlan),
        FOREIGN KEY (verificado_por) REFERENCES ADMIN_USUARIOS(idAdmin) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla PAGOS creada';

    // ===== 8. TENANTS =====
    $pdo->exec('CREATE TABLE TENANTS (
        idTenant VARCHAR(64) PRIMARY KEY,
        idLicencia VARCHAR(64) NOT NULL UNIQUE,
        db_name VARCHAR(120) NOT NULL UNIQUE,
        db_user VARCHAR(120) NOT NULL UNIQUE,
        db_host VARCHAR(255) NOT NULL,
        base_url VARCHAR(255) NOT NULL,
        storage_path VARCHAR(500) NOT NULL,
        api_key_hash CHAR(64) NOT NULL,
        estado ENUM("ACTIVO","SUSPENDIDO","ERROR") NOT NULL DEFAULT "ACTIVO",
        provisioned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tenant_status(estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla TENANTS creada';

    // ===== 9. TICKETS CENTRALES =====
    $pdo->exec('CREATE TABLE TICKETS (
        idTicket VARCHAR(50) PRIMARY KEY,
        idCliente VARCHAR(50) DEFAULT NULL,
        idTenant VARCHAR(64) DEFAULT NULL,
        tenantUserId VARCHAR(64) DEFAULT NULL,
        tenantUserName VARCHAR(200) DEFAULT NULL,
        origen VARCHAR(20) NOT NULL DEFAULT "PORTAL",
        asunto VARCHAR(200) NOT NULL,
        categoria VARCHAR(50) DEFAULT "GENERAL",
        prioridad ENUM("BAJA","NORMAL","ALTA","URGENTE") DEFAULT "NORMAL",
        estado ENUM("ABIERTO","EN_PROCESO","RESUELTO") DEFAULT "ABIERTO",
        escalado TINYINT(1) DEFAULT 0,
        creado DATETIME DEFAULT CURRENT_TIMESTAMP,
        actualizado DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cliente(idCliente),
        INDEX idx_tenant(idTenant,tenantUserId),
        INDEX idx_estado_actualizado(estado,actualizado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE TICKET_MENSAJES (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        idTicket VARCHAR(50) NOT NULL,
        autorTipo VARCHAR(20) NOT NULL,
        autorId VARCHAR(64) NOT NULL,
        mensaje TEXT NOT NULL,
        creado DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ticket_creado(idTicket,creado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE SESIONES (
        jti CHAR(32) PRIMARY KEY,
        tipo VARCHAR(20) NOT NULL,
        idUsuario VARCHAR(64) NOT NULL,
        expira DATETIME NOT NULL,
        creado DATETIME DEFAULT CURRENT_TIMESTAMP,
        revocado DATETIME DEFAULT NULL,
        INDEX idx_sesion_user(tipo,idUsuario),
        INDEX idx_sesion_expira(expira)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE LOGIN_RATE_LIMIT (
        scope_hash CHAR(64) PRIMARY KEY,
        intentos INT NOT NULL DEFAULT 0,
        ventana DATETIME NOT NULL,
        bloqueado_hasta DATETIME DEFAULT NULL,
        INDEX idx_bloqueo(bloqueado_hasta)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tickets, sesiones y rate limiting creados';

    // ===== 10. CONFIGURACION =====
    $pdo->exec('CREATE TABLE CONFIGURACION (
        parametro VARCHAR(100) PRIMARY KEY,
        valor TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $config = [
        ['NOMBRE_EMPRESA', 'Comandix'],
        ['SITIO_WEB', 'https://searpox.com'],
        ['WHATSAPP_VENTAS', '573235636580'],
        ['WHATSAPP_SOPORTE', '573235636580'],
        ['EMAIL_CONTACTO', 'searpix@gmail.com'],
        ['MONEDA', 'COP'],
        ['IMPUESTO', '0'],
        ['PASARELA_PAGO', 'WOMPI'],
        ['WOMPI_PUBLIC_KEY', ''],
        ['WOMPI_SECRET_KEY', ''],
        ['ACTIVACION_AUTOMATICA', 'NO'],
        ['DIAS_PRUEBA', '7'],
        ['LOGO_URL', 'https://i.ibb.co/39bHjfNc/freakers-png.png']
    ];
    $stmt = $pdo->prepare('INSERT INTO CONFIGURACION VALUES (?, ?)');
    foreach ($config as $c) $stmt->execute($c);
    $steps[] = 'Tabla CONFIGURACION creada';

    // ===== SEED DEMO DATA (opt-in only) =====
    if (filter_var(envValue('SEED_DEMO_DATA', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    // Demo client
    $demoClientPassword = envValue('SEED_DEMO_CLIENT_PASSWORD', bin2hex(random_bytes(16)));
    $clientHash = password_hash($demoClientPassword, PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO CLIENTES VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL, ?)')
        ->execute(['CLI-001', 'Diego', 'SearPix', 'searpix@gmail.com', '3235636580', 'Freakers', '1214722370', 'Colombia', 'Medellín', $clientHash, 'ACTIVO', '3235636580']);
    $steps[] = 'Cliente demo creado con contraseña definida por SEED_DEMO_CLIENT_PASSWORD.';

    // Demo license for Freakers
    $demoKey = envValue('SEED_DEMO_LICENSE_KEY', strtoupper(bin2hex(random_bytes(8))));
    $pdo->prepare('INSERT INTO LICENCIAS 
        (idLicencia, clave_activacion, idCliente, idPlan, negocio_nombre, negocio_nit, contacto_nombre, contacto_email, contacto_telefono, 
         plan, duracion_meses, fecha_activacion, fecha_expiracion, estado, max_usuarios, max_mesas, modulos_habilitados, activaciones, max_activaciones) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 365 DAY), "ACTIVA", ?, ?, ?, ?, ?)')
        ->execute([
            'LIC-DEMO-001', $demoKey, 'CLI-001', 'PLAN-ENT',
            'Freakers', '1214722370', 'Diego', 'searpix@gmail.com', '3235636580',
            'ENTERPRISE', 12, 10, 50,
            json_encode(['pos','domicilios','facturacion','whatsapp','dashboard','configuracion','delivery_apps','notificaciones']),
            1, 1
        ]);
    $steps[] = 'Licencia demo creada: ' . $demoKey;

    // Seed a couple pending licenses for testing
    function genKey() {
        $seg = function() { return strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)); };
        return $seg() . '-' . $seg() . '-' . $seg() . '-' . $seg();
    }

    $pdo->prepare('INSERT INTO LICENCIAS 
        (idLicencia, clave_activacion, idCliente, idPlan, negocio_nombre, plan, duracion_meses, estado, max_usuarios, max_mesas, modulos_habilitados, activaciones, max_activaciones) 
        VALUES (?, ?, ?, ?, ?, ?, ?, "PENDIENTE", ?, ?, ?, ?, ?)')
        ->execute([
            'LIC-TEST-001', genKey(), 'CLI-001', 'PLAN-BAS', 'Restaurante Test', 'BASICO', 1,
            3, 15, json_encode(['pos','categorias','productos','ordenes','pagos']), 0, 1
        ]);

    $pdo->prepare('INSERT INTO LICENCIAS 
        (idLicencia, clave_activacion, idCliente, idPlan, negocio_nombre, plan, duracion_meses, estado, max_usuarios, max_mesas, modulos_habilitados, activaciones, max_activaciones) 
        VALUES (?, ?, ?, ?, ?, ?, ?, "PENDIENTE", ?, ?, ?, ?, ?)')
        ->execute([
            'LIC-TEST-002', genKey(), 'CLI-001', 'PLAN-PRO', 'Restaurante Test Pro', 'PROFESIONAL', 12,
            8, 40, json_encode(['pos','domicilios','categorias','productos','ordenes','pagos','usuarios','dashboard','ticket_config','delivery_apps']), 0, 1
        ]);

    $steps[] = 'Licencias de prueba creadas (1 BASICO pendiente, 1 PROFESIONAL pendiente)';

    // ===== SEED DEMO PAYMENTS (para que el panel de Pagos no esté vacío) =====
    // 1) Pago PENDIENTE con comprobante subido -> el admin puede APROBARLO y activar la licencia
    $pdo->prepare('INSERT INTO PAGOS (idPago, idCliente, idLicencia, idPlan, monto, moneda, metodo, referencia, comprobante, estado, fecha_pago, created_at)
        VALUES (?, ?, ?, ?, ?, "COP", "NEQUI", ?, NULL, "PENDIENTE", NOW(), NOW())')
        ->execute(['PAY-DEMO-001', 'CLI-001', 'LIC-TEST-001', 'PLAN-BAS', 49900, 'NEQUI-0012345']);

    // 2) Otro pago PENDIENTE (transferencia) sobre la licencia PRO de prueba
    $pdo->prepare('INSERT INTO PAGOS (idPago, idCliente, idLicencia, idPlan, monto, moneda, metodo, referencia, estado, fecha_pago, created_at)
        VALUES (?, ?, ?, ?, ?, "COP", "TRANSFERENCIA", ?, "PENDIENTE", NOW(), NOW())')
        ->execute(['PAY-DEMO-002', 'CLI-001', 'LIC-TEST-002', 'PLAN-PRO', 899900, 'TRF-998877']);

    // 3) Pago APROBADO histórico (aparece en ingresos del mes y en el historial)
    $pdo->prepare('INSERT INTO PAGOS (idPago, idCliente, idLicencia, idPlan, monto, moneda, metodo, gateway_ref, estado, fecha_pago, fecha_verificacion, verificado_por, notas, created_at)
        VALUES (?, ?, ?, ?, ?, "COP", "WOMPI", ?, "APROBADO", NOW(), NOW(), "ADM-001", "Pago demo aprobado", NOW())')
        ->execute(['PAY-DEMO-003', 'CLI-001', 'LIC-DEMO-001', 'PLAN-ENT', 1499900, 'wompi_txn_demo_0001']);
    $steps[] = 'Pagos demo creados (2 pendientes por aprobar, 1 aprobado)';

    // Algunos eventos de auditoría de ejemplo para el Historial
    $logStmt = $pdo->prepare('INSERT INTO LICENCIA_LOG (idLicencia, idAdmin, idCliente, accion, descripcion, ip, fecha) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $logStmt->execute(['LIC-DEMO-001', 'ADM-001', 'CLI-001', 'LICENSE_CREATE', 'Licencia Enterprise creada para Freakers', '127.0.0.1']);
    $logStmt->execute(['LIC-TEST-001', null, 'CLI-001', 'ORDER_CREATE', 'Orden creada: BASICO (mensual) — $49.900 COP', '127.0.0.1']);
    $logStmt->execute(['LIC-DEMO-001', 'ADM-001', 'CLI-001', 'PAYMENT_APPROVE', 'Pago aprobado: PAY-DEMO-003 — WOMPI', '127.0.0.1']);
    $steps[] = 'Eventos de auditoría demo creados';


    }

    // ===== OUTPUT =====
    echo '<!DOCTYPE html><html><head><title>Comandix License Server — Setup</title>';
    echo '<style>';
    echo '*{margin:0;padding:0;box-sizing:border-box}';
    echo 'body{font-family:"Inter",monospace;background:#0B0F19;color:#e0e0e0;padding:30px;min-height:100vh}';
    echo 'h1{font-size:1.6rem;font-weight:900;background:linear-gradient(135deg,#6C3CE1,#E94560);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:6px}';
    echo '.ver{font-size:0.82rem;color:rgba(255,255,255,0.35);margin-bottom:20px}';
    echo 'h2{font-size:1.05rem;font-weight:700;color:#e94560;margin:18px 0 8px}';
    echo '.step{padding:8px 14px;margin:4px 0;border-radius:8px;background:rgba(74,222,128,0.08);border-left:3px solid #4ade80;font-size:0.82rem}';
    echo '.step.err{background:rgba(239,68,68,0.08);border-left-color:#ef4444}';
    echo '</style></head><body>';
    echo '<h1>🔐 Comandix License Server — Setup</h1>';
    echo '<div class="ver">Servidor de licencias independiente</div>';
    echo '<h2>Pasos ejecutados</h2>';
    foreach ($steps as $s) echo '<div class="step">' . $s . '</div>';
    if (!empty($errors)) {
        echo '<h2>Errores</h2>';
        foreach ($errors as $e) echo '<div class="step err">' . $e . '</div>';
    }
    echo '<div style="margin-top:24px;padding:20px;border-radius:14px;background:linear-gradient(135deg,rgba(108,60,225,0.15),rgba(74,222,128,0.08));border:1px solid rgba(108,60,225,0.3)">';
    echo '<h2 style="color:#4ade80;margin:0 0 10px">✅ Setup Completado!</h2>';
    echo '<p style="font-size:0.85rem;color:rgba(255,255,255,0.6)">10 tablas creadas. Admin y cliente demo listos.</p>';
    echo '<p style="margin:8px 0 0;font-size:0.78rem;color:rgba(255,255,255,0.4)">Credenciales definidas por el operador. No se muestran contraseñas.</p>';
    echo '</div>';
    echo '</body></html>';

} catch (Exception $e) {
    echo '<div class="step err">Error critico: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '<div class="step err">Archivo: ' . $e->getFile() . ' linea ' . $e->getLine() . '</div>';
}
