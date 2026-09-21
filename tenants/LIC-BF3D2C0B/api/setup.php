<?php
/* ============================================
   FREAKERS POS v6 - Setup & Database Reset
   Phase 1: Includes License System tables
   
   This script:
   1. Drops and recreates the entire database
   2. Creates all tables (13 original + 5 new licensing)
   3. Inserts ALL default data
   4. Seeds a demo license for testing
   5. Verifies everything works
   
   Run via browser: http://localhost/searpox/api/setup.php
   ============================================ */

header('Content-Type: text/html; charset=utf-8');

// Bloqueo de seguridad: exige localhost + SETUP_TOKEN + candado de instalacion.
require_once __DIR__ . '/_setup_guard.php';

$steps = [];
$errors = [];

try {
    require_once __DIR__ . '/db_config.php';
    $pdo = new PDO(
        'mysql:host=' . SETUP_DB_HOST . ';charset=utf8mb4',
        SETUP_DB_USER,
        SETUP_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $steps[] = 'Conexion a MySQL exitosa';

    // Create database
    $pdo->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
    $pdo->exec('CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec("SET time_zone = '-05:00'");
    $steps[] = 'Base de datos freakers_pos creada';

    // ===== 1. USUARIOS =====
    $pdo->exec('CREATE TABLE USUARIOS (
        idUsuario VARCHAR(50) PRIMARY KEY,
        usuario VARCHAR(50) NOT NULL UNIQUE,
        clave VARCHAR(255) NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        apellido VARCHAR(100) DEFAULT NULL,
        rol ENUM("ADMIN","MESERO") NOT NULL DEFAULT "MESERO",
        estado ENUM("ACTIVO","INACTIVO") NOT NULL DEFAULT "ACTIVO",
        fechaCreacion DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $adminPassword = getenv('SETUP_POS_ADMIN_PASSWORD') ?: bin2hex(random_bytes(16));
    $waiterPassword = getenv('SETUP_POS_WAITER_PASSWORD') ?: bin2hex(random_bytes(16));
    $hash1 = password_hash($adminPassword, PASSWORD_DEFAULT);
    $hash2 = password_hash($waiterPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO USUARIOS (idUsuario, usuario, clave, nombre, apellido, rol, estado, fechaCreacion) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute(['U001', 'SEARPIX', $hash1, 'Diego', NULL, 'ADMIN', 'ACTIVO']);
    $stmt->execute(['U002', 'FREAKERS', $hash2, 'Freakers', NULL, 'MESERO', 'ACTIVO']);
    $steps[] = 'Tabla USUARIOS creada con contraseñas seguras. Use SETUP_POS_ADMIN_PASSWORD y SETUP_POS_WAITER_PASSWORD para definirlas.';

    // ===== 2. MESAS =====
    $pdo->exec('CREATE TABLE MESAS (
        idMesa VARCHAR(20) PRIMARY KEY,
        numero VARCHAR(20) NOT NULL,
        capacidad INT NOT NULL DEFAULT 4,
        estado ENUM("LIBRE","OCUPADA","INACTIVA") NOT NULL DEFAULT "LIBRE",
        clase ENUM("BARRA","VIP","MESAS") NOT NULL DEFAULT "MESAS"
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $mesas = [
        ['M1','1',4,'LIBRE','MESAS'],['M2','2',4,'LIBRE','MESAS'],['M3','3',4,'LIBRE','MESAS'],['M4','4',4,'LIBRE','MESAS'],
        ['M5','5',4,'LIBRE','MESAS'],['M6','6',4,'LIBRE','MESAS'],['M7','7',4,'LIBRE','MESAS'],['M8','8',4,'LIBRE','MESAS'],
        ['M9','9',4,'LIBRE','MESAS'],['M10','10',4,'LIBRE','MESAS'],['M11','11',4,'LIBRE','MESAS'],['M12','12',4,'OCUPADA','MESAS'],
        ['M13','13',4,'LIBRE','MESAS'],
        ['VIP1','VIP 1',2,'LIBRE','VIP'],['VIP2','VIP 2',2,'LIBRE','VIP'],['VIP3','VIP 3',2,'LIBRE','VIP'],
        ['BARRA1','BARRA 1',1,'LIBRE','BARRA'],['BARRA2','BARRA 2',1,'LIBRE','BARRA'],['BARRA3','BARRA 3',1,'LIBRE','BARRA'],['BARRA4','BARRA 4',1,'LIBRE','BARRA']
    ];
    $stmt = $pdo->prepare('INSERT INTO MESAS VALUES (?, ?, ?, ?, ?)');
    foreach ($mesas as $m) $stmt->execute($m);
    $steps[] = 'Tabla MESAS creada con ' . count($mesas) . ' mesas';

    // ===== 3. CATEGORIAS =====
    $pdo->exec('CREATE TABLE CATEGORIAS (
        idCategoria VARCHAR(50) PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        imagen TEXT DEFAULT NULL,
        estado ENUM("ACTIVO","INACTIVO") NOT NULL DEFAULT "ACTIVO"
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $categorias = [
        ['CAT-ENT','Entradas','https://cdn-icons-png.flaticon.com/512/5306/5306580.png','ACTIVO'],
        ['CAT-BEB','Bebidas','https://cdn-icons-png.flaticon.com/512/2391/2391701.png','ACTIVO'],
        ['CAT-GAS','Gaseosas','https://cdn-icons-png.flaticon.com/512/3050/3050130.png','ACTIVO'],
        ['CAT-JUG','Jugos Naturales','https://cdn-icons-png.flaticon.com/512/2442/2442019.png','ACTIVO'],
        ['CAT-LIM','Limonadas',NULL,'ACTIVO'],
        ['CAT-CER','Cervezas','https://cdn-icons-png.flaticon.com/512/3728/3728021.png','ACTIVO'],
        ['CAT-MIC','Micheladas','https://cdn-icons-png.flaticon.com/512/7924/7924161.png','ACTIVO'],
        ['CAT-COC','Cocteles','https://cdn-icons-png.flaticon.com/512/7285/7285889.png','ACTIVO'],
        ['CAT-HAM','Hamburguesas','https://cdn-icons-png.flaticon.com/512/3075/3075977.png','ACTIVO'],
        ['CAT-BAB','Baby Burgers','https://i.ibb.co/whWMcfhq/images.png','ACTIVO'],
        ['CAT-HF','HamFreaks','https://cdn-icons-png.flaticon.com/512/2278/2278992.png','ACTIVO'],
        ['CAT-ESP','Especiales','https://i.ibb.co/39bHjfNc/freakers-png.png','ACTIVO'],
        ['CAT-PER','Perros','https://cdn-icons-png.flaticon.com/512/2674/2674083.png','ACTIVO'],
        ['CAT-PAP','Papas','https://cdn-icons-png.flaticon.com/512/1057/1057356.png','ACTIVO'],
        ['CAT-ADI','Adiciones','https://cdn-icons-png.flaticon.com/512/9224/9224691.png','ACTIVO']
    ];
    $stmt = $pdo->prepare('INSERT INTO CATEGORIAS VALUES (?, ?, ?, ?)');
    foreach ($categorias as $c) $stmt->execute($c);
    $steps[] = 'Tabla CATEGORIAS creada con ' . count($categorias) . ' categorias';

    // ===== 4. PRODUCTOS =====
    $pdo->exec('CREATE TABLE PRODUCTOS (
        idProducto VARCHAR(50) PRIMARY KEY,
        idCategoria VARCHAR(50) NOT NULL,
        nombre VARCHAR(200) NOT NULL,
        precio DECIMAL(12,2) NOT NULL DEFAULT 0,
        imagen TEXT DEFAULT NULL,
        estado ENUM("ACTIVO","INACTIVO") NOT NULL DEFAULT "ACTIVO",
        FOREIGN KEY (idCategoria) REFERENCES CATEGORIAS(idCategoria) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $productos = [
        ['PROD-001','CAT-ENT','Nachos con Guacamole',8000,'https://i.ibb.co/39bHjfNc/freakers-png.png','ACTIVO'],
        ['PROD-002','CAT-ENT','Patacones con Guacamole',8000,'https://cdn-icons-png.flaticon.com/512/5306/5306580.png','ACTIVO'],
        ['PROD-003','CAT-ENT','Quesadilla x3',10000,NULL,'ACTIVO'],
        ['PROD-004','CAT-ENT','Patacones con Carne x6',15000,NULL,'ACTIVO'],
        ['PROD-005','CAT-BEB','Frape Colombiano',12000,NULL,'ACTIVO'],
        ['PROD-006','CAT-GAS','Coca-Cola 400ml',5000,NULL,'ACTIVO'],
        ['PROD-007','CAT-LIM','Limonada de Coco',9000,NULL,'ACTIVO'],
        ['PROD-008','CAT-LIM','Limonada de Cereza',9000,NULL,'ACTIVO'],
        ['PROD-009','CAT-LIM','Limonada Mango Biche',9000,NULL,'ACTIVO'],
        ['PROD-010','CAT-LIM','Limonada Natural',7000,NULL,'ACTIVO'],
        ['PROD-011','CAT-JUG','Jugo Natural en Agua: Fresa',6000,NULL,'ACTIVO'],
        ['PROD-012','CAT-JUG','Jugo Natural en Agua: Mora',6000,NULL,'ACTIVO'],
        ['PROD-013','CAT-JUG','Jugo Natural en Agua: Mango',6000,NULL,'ACTIVO'],
        ['PROD-014','CAT-JUG','Jugo Natural en Agua: Maracuya',6000,NULL,'ACTIVO'],
        ['PROD-015','CAT-JUG','Jugo Natural en Agua: Mandarina',6000,NULL,'ACTIVO'],
        ['PROD-016','CAT-JUG','Jugo Natural en Agua: Lulo',6000,NULL,'ACTIVO'],
        ['PROD-017','CAT-JUG','Jugo Natural en Leche: Fresa',8000,NULL,'ACTIVO'],
        ['PROD-018','CAT-JUG','Jugo Natural en Leche: Mora',8000,NULL,'ACTIVO'],
        ['PROD-019','CAT-JUG','Jugo Natural en Leche: Mango',8000,NULL,'ACTIVO'],
        ['PROD-020','CAT-JUG','Jugo Natural en Leche: Maracuya',8000,NULL,'ACTIVO'],
        ['PROD-021','CAT-CER','Cerveza Aguila',4000,NULL,'ACTIVO'],
        ['PROD-022','CAT-CER','Cerveza Poker',4000,NULL,'ACTIVO'],
        ['PROD-023','CAT-CER','Cerveza Club Colombia',5000,NULL,'ACTIVO'],
        ['PROD-024','CAT-CER','Cerveza Corona',8000,NULL,'ACTIVO'],
        ['PROD-025','CAT-MIC','Michelada Clasica',12000,NULL,'ACTIVO'],
        ['PROD-026','CAT-MIC','Michelada de Mango',14000,NULL,'ACTIVO'],
        ['PROD-027','CAT-MIC','Michelada de Fresa',14000,NULL,'ACTIVO'],
        ['PROD-028','CAT-COC','Coco Loco',18000,NULL,'ACTIVO'],
        ['PROD-029','CAT-COC','Margarita',16000,NULL,'ACTIVO'],
        ['PROD-030','CAT-HAM','Hamburguesa Clasica',18000,NULL,'ACTIVO'],
        ['PROD-031','CAT-HAM','Hamburguesa Doble',25000,NULL,'ACTIVO'],
        ['PROD-032','CAT-HAM','Hamburguesa BBQ',22000,NULL,'ACTIVO'],
        ['PROD-033','CAT-BAB','Baby Burger Sencilla',10000,NULL,'ACTIVO'],
        ['PROD-034','CAT-BAB','Baby Burger Queso',12000,NULL,'ACTIVO'],
        ['PROD-035','CAT-HF','HamFreaks Clasica',30000,'https://cdn-icons-png.flaticon.com/512/2278/2278992.png','ACTIVO'],
        ['PROD-036','CAT-HF','HamFreaks Doble',40000,NULL,'ACTIVO'],
        ['PROD-037','CAT-ESP','Freakers Especial',35000,'https://i.ibb.co/39bHjfNc/freakers-png.png','ACTIVO'],
        ['PROD-038','CAT-PER','Perro Sencillo',8000,NULL,'ACTIVO'],
        ['PROD-039','CAT-PER','Perro Doble',12000,NULL,'ACTIVO'],
        ['PROD-040','CAT-PER','Perro Choripapa',15000,NULL,'ACTIVO'],
        ['PROD-041','CAT-PAP','Papas Clasicas',8000,NULL,'ACTIVO'],
        ['PROD-042','CAT-PAP','Papas Cheddar',10000,NULL,'ACTIVO'],
        ['PROD-043','CAT-PAP','Papas Cargadas',14000,NULL,'ACTIVO'],
        ['PROD-044','CAT-ADI','Queso Extra',3000,NULL,'ACTIVO'],
        ['PROD-045','CAT-ADI','Carne Extra',5000,NULL,'ACTIVO'],
        ['PROD-046','CAT-ADI','Tocineta Extra',3000,NULL,'ACTIVO'],
        ['PROD-047','CAT-ADI','Huevo Extra',2000,NULL,'ACTIVO']
    ];
    $stmt = $pdo->prepare('INSERT INTO PRODUCTOS VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($productos as $p) $stmt->execute($p);
    $steps[] = 'Tabla PRODUCTOS creada con ' . count($productos) . ' productos';

    // ===== 5. ORDENES =====
    $pdo->exec('CREATE TABLE ORDENES (
        idOrden VARCHAR(50) PRIMARY KEY,
        idMesa VARCHAR(20) NOT NULL,
        idUsuario VARCHAR(50) DEFAULT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        estado ENUM("PENDIENTE","ACTIVA","PAGADO","EN_PREPARACION","EN_CAMINO","ENTREGADO","COMPLETADO","CANCELADO","ELIMINADA") NOT NULL DEFAULT "PENDIENTE",
        descuento DECIMAL(12,2) NOT NULL DEFAULT 0,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        egreso DECIMAL(12,2) NOT NULL DEFAULT 0,
        comentario TEXT DEFAULT NULL,
        canal VARCHAR(50) DEFAULT "LOCAL",
        idApp VARCHAR(50) DEFAULT NULL,
        cliente_nombre VARCHAR(200) DEFAULT NULL,
        cliente_telefono VARCHAR(50) DEFAULT NULL,
        cliente_direccion TEXT DEFAULT NULL,
        domicilio DECIMAL(12,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (idUsuario) REFERENCES USUARIOS(idUsuario) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla ORDENES creada';

    // ===== 6. DETALLE_ORDEN =====
    $pdo->exec('CREATE TABLE DETALLE_ORDEN (
        idDetalle VARCHAR(80) PRIMARY KEY,
        idOrden VARCHAR(50) NOT NULL,
        idProducto VARCHAR(50) NOT NULL,
        cantidad INT NOT NULL DEFAULT 1,
        precioUnitario DECIMAL(12,2) NOT NULL DEFAULT 0,
        totalLinea DECIMAL(12,2) NOT NULL DEFAULT 0,
        producto VARCHAR(200) DEFAULT NULL,
        FOREIGN KEY (idOrden) REFERENCES ORDENES(idOrden) ON DELETE CASCADE,
        FOREIGN KEY (idProducto) REFERENCES PRODUCTOS(idProducto) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla DETALLE_ORDEN creada';

    // ===== 7. PAGOS =====
    $pdo->exec('CREATE TABLE PAGOS (
        idPago VARCHAR(50) PRIMARY KEY,
        idOrden VARCHAR(50) NOT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        metodo VARCHAR(50) NOT NULL,
        monto DECIMAL(12,2) NOT NULL DEFAULT 0,
        idUsuario VARCHAR(50) DEFAULT NULL,
        FOREIGN KEY (idOrden) REFERENCES ORDENES(idOrden) ON DELETE CASCADE,
        FOREIGN KEY (idUsuario) REFERENCES USUARIOS(idUsuario) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla PAGOS creada';

    // ===== 8. MOVIMIENTOS =====
    $pdo->exec('CREATE TABLE MOVIMIENTOS (
        idMovimiento VARCHAR(50) PRIMARY KEY,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        idUsuario VARCHAR(50) DEFAULT NULL,
        accion VARCHAR(50) NOT NULL,
        idReferencia VARCHAR(50) DEFAULT NULL,
        descripcion TEXT DEFAULT NULL,
        FOREIGN KEY (idUsuario) REFERENCES USUARIOS(idUsuario) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla MOVIMIENTOS creada';

    // ===== 9. CONFIGURACION =====
    $pdo->exec('CREATE TABLE CONFIGURACION (
        parametro VARCHAR(100) PRIMARY KEY,
        valor TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $config = [
        ['NOMBRE_NEGOCIO','Freakers'],
        ['NOMBRE_RESTAURANTE','FREAKERS'],
        ['RAZON_SOCIAL','BURGERS AND FAST FOOD'],
        ['NIT','1214722370'],
        ['DIRECCION','Medellin, Colombia'],
        ['TELEFONO','310 574 3129'],
        ['SUBTITULO','Burgers & Fast Food'],
        ['LOGO','https://i.ibb.co/39bHjfNc/freakers-png.png'],
        ['COLOR_PRIMARIO','#6C3CE1'],
        ['COLOR_SECUNDARIO','#1A1A2E'],
        ['COLOR_ACENTO','#E94560'],
        ['COLOR_FONDO','#16213E'],
        ['TIPOGRAFIA','Inter'],
        ['VALOR_DOMICILIO','5000'],
        ['AUTO_IMPRIMIR','SI'],
        ['MONEDA','COP'],
        ['IMPUESTO','0'],
        ['PEDIDOS_COCINA','SI'],
        ['WHATSAPP_NUMERO',''],
        ['WHATSAPP_API_TOKEN',''],
        ['WHATSAPP_WABA_ID',''],
        // === NUEVOS PARAMETROS v6 ===
        ['MODO_TEMA','oscuro'],
        ['ANIMACIONES_ACTIVAS','SI'],
        ['IDIOMA_PRINCIPAL','es'],
        ['IDIOMAS_DISPONIBLES','["es","en","pt"]'],
        ['LICENCIA_SERVER',''],
        ['LICENCIA_CLAVE','']
    ];
    $stmt = $pdo->prepare('INSERT INTO CONFIGURACION VALUES (?, ?)');
    foreach ($config as $c) $stmt->execute($c);
    $steps[] = 'Tabla CONFIGURACION creada con ' . count($config) . ' parametros (incluye v6: tema, animaciones, idioma, licencia)';

    // ===== 10. APPS_DELIVERY =====
    $pdo->exec('CREATE TABLE APPS_DELIVERY (
        idApp VARCHAR(50) PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        logo TEXT DEFAULT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        comision DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        credencial VARCHAR(255) DEFAULT NULL,
        orden INT DEFAULT 0,
        webhook_url VARCHAR(500) DEFAULT NULL,
        api_key VARCHAR(500) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $apps = [
        ['APP-WHATSAPP','WhatsApp','https://cdn-icons-png.flaticon.com/512/1240/1240979.png',1,0.00,NULL,1,NULL,NULL],
        ['APP-DIDI','DidiFood','https://cdn-icons-png.flaticon.com/512/2920/2920349.png',1,20.00,NULL,2,NULL,NULL],
        ['APP-UBER','UberEats','https://cdn-icons-png.flaticon.com/512/3490/3490285.png',1,25.00,NULL,3,NULL,NULL],
        ['APP-RAPPI','Rappi','https://cdn-icons-png.flaticon.com/512/3490/3490400.png',1,22.00,NULL,4,NULL,NULL],
        ['APP-IFOOD','iFood','https://cdn-icons-png.flaticon.com/512/3046/3046921.png',1,18.00,NULL,5,NULL,NULL],
        ['APP-PEDIDOSYA','PedidosYa','https://cdn-icons-png.flaticon.com/512/3490/3490530.png',1,15.00,NULL,6,NULL,NULL]
    ];
    $stmt = $pdo->prepare('INSERT INTO APPS_DELIVERY VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($apps as $a) $stmt->execute($a);
    $steps[] = 'Tabla APPS_DELIVERY creada con ' . count($apps) . ' apps';

    // ===== 11. RESPUESTAS_RAPIDAS =====
    $pdo->exec('CREATE TABLE RESPUESTAS_RAPIDAS (
        id VARCHAR(50) PRIMARY KEY,
        nombre VARCHAR(200) NOT NULL,
        mensaje TEXT NOT NULL,
        variables VARCHAR(500) DEFAULT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        orden INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $respuestas = [
        ['RR-001','Confirmacion','Hola {cliente}! Tu pedido #{id_orden} ha sido confirmado. Productos: {productos}. Total: {total_domicilio}. {negocio}','{cliente},{productos},{total_domicilio},{id_orden},{negocio}',1,1],
        ['RR-002','En Camino','Hola {cliente}! Tu domicilio #{id_orden} ya va en camino. Llegara pronto a {direccion}. {negocio}','{cliente},{id_orden},{direccion},{negocio}',1,2],
        ['RR-003','Entregado','Hola {cliente}! Tu domicilio #{id_orden} fue entregado exitosamente. Gracias por pedir en {negocio}!','{cliente},{id_orden},{negocio}',1,3],
        ['RR-004','Cancelado','Hola {cliente}. Lamentamos informarte que tu domicilio #{id_orden} ha sido cancelado. Contactanos al {telefono}. {negocio}','{cliente},{id_orden},{telefono},{negocio}',1,4]
    ];
    $stmt = $pdo->prepare('INSERT INTO RESPUESTAS_RAPIDAS VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($respuestas as $r) $stmt->execute($r);
    $steps[] = 'Tabla RESPUESTAS_RAPIDAS creada con ' . count($respuestas) . ' respuestas';

    // ===== 12. TICKET_CONFIG =====
    $pdo->exec('CREATE TABLE TICKET_CONFIG (
        id VARCHAR(50) PRIMARY KEY,
        bloque VARCHAR(50) NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        contenido TEXT DEFAULT NULL,
        orden INT NOT NULL DEFAULT 0,
        opciones JSON DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $tickets = [
        ['TKT-HEADER','HEADER',1,'{logo}',0,'{"alineacion":"centro","tamano":"grande"}'],
        ['TKT-NEGOCIO','NEGOCIO',1,'{negocio}\n{razon_social}\nNIT: {nit}\n{direccion} - {telefono}',1,'{"alineacion":"centro","tamano":"normal"}'],
        ['TKT-ORDEN','ORDEN',1,'Orden: {id_orden}\nFecha: {fecha}\nTipo: {tipo}\n{info_cliente}',2,'{"alineacion":"izquierda","tamano":"normal"}'],
        ['TKT-ITEMS','ITEMS',1,'{items_tabla}',3,'{"alineacion":"izquierda","tamano":"normal"}'],
        ['TKT-TOTALES','TOTALES',1,'Subtotal: {subtotal}\nDomicilio: {domicilio}\n----------------\nTOTAL: {total}',4,'{"alineacion":"derecha","tamano":"normal"}'],
        ['TKT-FOOTER','FOOTER',1,'Gracias por tu compra!\n{negocio}',5,'{"alineacion":"centro","tamano":"normal"}'],
        ['TKT-PUBLICIDAD','PUBLICIDAD',0,'Siguenos en @freakers\nInstagram: @freakersburger',6,'{"alineacion":"centro","tamano":"pequeno"}']
    ];
    $stmt = $pdo->prepare('INSERT INTO TICKET_CONFIG VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($tickets as $t) $stmt->execute($t);
    $steps[] = 'Tabla TICKET_CONFIG creada con ' . count($tickets) . ' bloques';

    // ===== 13. NOTIFICACIONES =====
    $pdo->exec('CREATE TABLE NOTIFICACIONES (
        idNotificacion VARCHAR(50) PRIMARY KEY,
        tipo VARCHAR(50) NOT NULL,
        titulo VARCHAR(200) NOT NULL,
        mensaje TEXT DEFAULT NULL,
        idReferencia VARCHAR(50) DEFAULT NULL,
        leida TINYINT(1) NOT NULL DEFAULT 0,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla NOTIFICACIONES creada';

    // ============================================================
    //    NUEVAS TABLAS v6 — SISTEMA DE LICENCIAS
    // ============================================================

    // ===== 14. LICENCIAS =====
    $pdo->exec('CREATE TABLE LICENCIAS (
        idLicencia VARCHAR(50) PRIMARY KEY,
        clave_activacion VARCHAR(255) NOT NULL UNIQUE,
        negocio_nombre VARCHAR(200) NOT NULL,
        negocio_nit VARCHAR(50) DEFAULT NULL,
        contacto_email VARCHAR(200) DEFAULT NULL,
        contacto_telefono VARCHAR(50) DEFAULT NULL,
        plan ENUM("BASICO","PROFESIONAL","ENTERPRISE") NOT NULL DEFAULT "BASICO",
        fecha_activacion DATETIME DEFAULT NULL,
        fecha_expiracion DATETIME DEFAULT NULL,
        estado ENUM("ACTIVA","SUSPENDIDA","EXPIRADA","REVOCADA") NOT NULL DEFAULT "ACTIVA",
        max_usuarios INT DEFAULT 5,
        max_mesas INT DEFAULT 20,
        modulos_habilitados JSON DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla LICENCIAS creada (nueva v6)';

    // ===== 15. LICENCIA_ACTIVA =====
    $pdo->exec('CREATE TABLE LICENCIA_ACTIVA (
        id INT AUTO_INCREMENT PRIMARY KEY,
        idLicencia VARCHAR(50) NOT NULL,
        hardware_id VARCHAR(255) DEFAULT NULL,
        activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_check DATETIME DEFAULT NULL,
        check_interval INT DEFAULT 3600,
        FOREIGN KEY (idLicencia) REFERENCES LICENCIAS(idLicencia) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla LICENCIA_ACTIVA creada (nueva v6)';

    // ===== 16. MESAS_UNIDAS =====
    $pdo->exec('CREATE TABLE MESAS_UNIDAS (
        idUnion VARCHAR(50) PRIMARY KEY,
        mesas JSON NOT NULL,
        label VARCHAR(100) NOT NULL,
        estado ENUM("ACTIVA","DISUELTA") NOT NULL DEFAULT "ACTIVA",
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
        idOrdenCompartida VARCHAR(50) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla MESAS_UNIDAS creada (preparada para Fase 3)';

    // ===== 17. FACTURAS =====
    $pdo->exec('CREATE TABLE FACTURAS (
        idFactura VARCHAR(50) PRIMARY KEY,
        idOrden VARCHAR(50) DEFAULT NULL,
        numero_factura VARCHAR(50) NOT NULL UNIQUE,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        tipo ENUM("ELECTRONICA","POS","SOPORTE","NOTA_CREDITO") NOT NULL DEFAULT "POS",
        estado ENUM("BORRADOR","EMITIDA","ANULADA") NOT NULL DEFAULT "BORRADOR",
        cliente_nombre VARCHAR(200) DEFAULT NULL,
        cliente_nit VARCHAR(50) DEFAULT NULL,
        cliente_email VARCHAR(200) DEFAULT NULL,
        cliente_telefono VARCHAR(50) DEFAULT NULL,
        cliente_direccion TEXT DEFAULT NULL,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        iva DECIMAL(12,2) NOT NULL DEFAULT 0,
        descuento DECIMAL(12,2) NOT NULL DEFAULT 0,
        domicilio DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        metodo_pago VARCHAR(50) DEFAULT "EFECTIVO",
        resolucion_dian VARCHAR(100) DEFAULT NULL,
        prefijo_factura VARCHAR(20) DEFAULT NULL,
        consecutivo INT DEFAULT 0,
        qr_code TEXT DEFAULT NULL,
        cude TEXT DEFAULT NULL,
        xml_content LONGTEXT DEFAULT NULL,
        pdf_path VARCHAR(500) DEFAULT NULL,
        diseno_json JSON DEFAULT NULL,
        FOREIGN KEY (idOrden) REFERENCES ORDENES(idOrden) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla FACTURAS creada (preparada para Fase 8)';

    // ===== 18. FACTURA_DISENO =====
    $pdo->exec('CREATE TABLE FACTURA_DISENO (
        idDiseno VARCHAR(50) PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        bloques JSON NOT NULL,
        tamano ENUM("58mm","80mm","A4","PERSONALIZADO") DEFAULT "80mm",
        orientacion ENUM("vertical","horizontal") DEFAULT "vertical",
        colores JSON DEFAULT NULL,
        activo TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $steps[] = 'Tabla FACTURA_DISENO creada (preparada para Fase 8)';

    // ===== 19. IDIOMAS =====
    $pdo->exec('CREATE TABLE IDIOMAS (
        codigo VARCHAR(5) PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        activo TINYINT(1) DEFAULT 1,
        flag_emoji VARCHAR(10) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    
    $idiomas = [
        ['es', 'Espanol', 1, '🇪🇸'],
        ['en', 'English', 1, '🇺🇸'],
        ['pt', 'Portugues', 1, '🇧🇷'],
        ['fr', 'Francais', 0, '🇫🇷']
    ];
    $stmt = $pdo->prepare('INSERT INTO IDIOMAS VALUES (?, ?, ?, ?)');
    foreach ($idiomas as $i) $stmt->execute($i);
    $steps[] = 'Tabla IDIOMAS creada con 4 idiomas (preparada para Fase 7)';

    // ===== 20. TRADUCCIONES =====
    $pdo->exec('CREATE TABLE TRADUCCIONES (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo_idioma VARCHAR(5) NOT NULL,
        grupo VARCHAR(50) NOT NULL,
        clave VARCHAR(200) NOT NULL,
        valor TEXT NOT NULL,
        FOREIGN KEY (codigo_idioma) REFERENCES IDIOMAS(codigo) ON DELETE CASCADE,
        UNIQUE KEY idx_trad (codigo_idioma, grupo, clave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    
    // Seed Spanish translations (default language, complete)
    $traducciones_es = [
        ['es', 'auth', 'btn_login', 'Iniciar Sesion'],
        ['es', 'auth', 'lbl_user', 'Usuario'],
        ['es', 'auth', 'lbl_pass', 'Contrasena'],
        ['es', 'auth', 'lbl_placeholder_user', 'Tu usuario'],
        ['es', 'auth', 'lbl_placeholder_pass', '••••••••'],
        ['es', 'auth', 'msg_verifying', 'Verificando...'],
        ['es', 'auth', 'msg_welcome', 'Bienvenido,'],
        ['es', 'auth', 'msg_invalid', 'Usuario o contrasena incorrectos.'],
        ['es', 'auth', 'msg_complete_fields', 'Completa los campos'],
        ['es', 'auth', 'msg_session_expired', 'Sesion expirada.'],
        ['es', 'nav', 'dashboard', 'Dashboard'],
        ['es', 'nav', 'panel', 'Panel Mesero'],
        ['es', 'nav', 'domicilios', 'Domicilios'],
        ['es', 'nav', 'mesas', 'Mesas'],
        ['es', 'nav', 'categorias', 'Categorias'],
        ['es', 'nav', 'productos', 'Productos'],
        ['es', 'nav', 'ordenes', 'Ordenes'],
        ['es', 'nav', 'pagos', 'Pagos'],
        ['es', 'nav', 'usuarios', 'Usuarios'],
        ['es', 'nav', 'movimientos', 'Movimientos'],
        ['es', 'nav', 'configuracion', 'Configuracion'],
        ['es', 'nav', 'logout', 'Cerrar Sesion'],
        ['es', 'pos', 'btn_cobrar', 'Cobrar'],
        ['es', 'pos', 'btn_create_order', 'Crear Orden'],
        ['es', 'pos', 'lbl_total', 'Total'],
        ['es', 'pos', 'lbl_subtotal', 'Subtotal'],
        ['es', 'pos', 'lbl_discount', 'Descuento'],
        ['es', 'pos', 'lbl_delivery_fee', 'Domicilio'],
        ['es', 'pos', 'lbl_cart', 'Carrito'],
        ['es', 'pos', 'lbl_empty_cart', 'Carrito vacio'],
        ['es', 'pos', 'lbl_add', 'Agregar'],
        ['es', 'pos', 'lbl_remove', 'Quitar'],
        ['es', 'pos', 'lbl_qty', 'Cant'],
        ['es', 'license', 'title_expired', 'Licencia Expirada'],
        ['es', 'license', 'title_no_license', 'Sin Licencia'],
        ['es', 'license', 'title_revoked', 'Licencia Revocada'],
        ['es', 'license', 'title_suspended', 'Licencia Suspendida'],
        ['es', 'license', 'title_no_connection', 'Sin Conexion al Servidor'],
        ['es', 'license', 'msg_expired', 'Tu licencia ha expirado. Contacta al proveedor para renovar.'],
        ['es', 'license', 'msg_no_license', 'No hay licencia activa en este equipo. Ingresa una clave de activacion.'],
        ['es', 'license', 'msg_revoked', 'Esta licencia ha sido revocada por el administrador.'],
        ['es', 'license', 'msg_suspended', 'Tu licencia esta suspendida. Contacta al proveedor.'],
        ['es', 'license', 'msg_no_connection', 'No se pudo verificar la licencia. Conecta a internet para continuar usando el sistema despues de 24 horas sin verificacion.'],
        ['es', 'license', 'lbl_activation_key', 'Clave de Activacion'],
        ['es', 'license', 'lbl_placeholder_key', 'XXXX-XXXX-XXXX-XXXX'],
        ['es', 'license', 'btn_activate', 'Activar Licencia'],
        ['es', 'license', 'btn_buy', 'Comprar Licencia'],
        ['es', 'license', 'btn_remove', 'Remover Licencia'],
        ['es', 'license', 'msg_activating', 'Activando...'],
        ['es', 'license', 'msg_activated', 'Licencia activada exitosamente!'],
        ['es', 'license', 'msg_activation_failed', 'Error al activar la licencia.'],
        ['es', 'license', 'msg_enter_key', 'Ingresa la clave de activacion.'],
        ['es', 'license', 'lbl_plan', 'Plan'],
        ['es', 'license', 'lbl_expires', 'Expira'],
        ['es', 'license', 'lbl_business', 'Negocio'],
        ['es', 'license', 'lbl_never', 'Nunca'],
        ['es', 'license', 'lbl_valid', 'Valida'],
        ['es', 'general', 'lbl_search', 'Buscar'],
        ['es', 'general', 'lbl_actions', 'Acciones'],
        ['es', 'general', 'lbl_edit', 'Editar'],
        ['es', 'general', 'lbl_delete', 'Eliminar'],
        ['es', 'general', 'lbl_save', 'Guardar'],
        ['es', 'general', 'lbl_cancel', 'Cancelar'],
        ['es', 'general', 'lbl_close', 'Cerrar'],
        ['es', 'general', 'lbl_confirm', 'Confirmar'],
        ['es', 'general', 'lbl_yes', 'Si'],
        ['es', 'general', 'lbl_no', 'No'],
        ['es', 'general', 'lbl_all', 'Todos'],
        ['es', 'general', 'lbl_active', 'Activo'],
        ['es', 'general', 'lbl_inactive', 'Inactivo'],
        ['es', 'general', 'msg_saved', 'Guardado exitosamente'],
        ['es', 'general', 'msg_deleted', 'Eliminado exitosamente'],
        ['es', 'general', 'msg_error', 'Ocurrio un error'],
        ['es', 'general', 'msg_no_results', 'Sin resultados'],
        ['es', 'general', 'msg_confirm_delete', 'Estas seguro de eliminar este elemento?']
    ];
    $stmt = $pdo->prepare('INSERT INTO TRADUCCIONES (codigo_idioma, grupo, clave, valor) VALUES (?, ?, ?, ?)');
    foreach ($traducciones_es as $t) $stmt->execute($t);
    
    // Seed English translations
    $traducciones_en = [
        ['en', 'auth', 'btn_login', 'Sign In'],
        ['en', 'auth', 'lbl_user', 'Username'],
        ['en', 'auth', 'lbl_pass', 'Password'],
        ['en', 'auth', 'lbl_placeholder_user', 'Your username'],
        ['en', 'auth', 'lbl_placeholder_pass', '••••••••'],
        ['en', 'auth', 'msg_verifying', 'Verifying...'],
        ['en', 'auth', 'msg_welcome', 'Welcome,'],
        ['en', 'auth', 'msg_invalid', 'Incorrect username or password.'],
        ['en', 'auth', 'msg_complete_fields', 'Fill in the fields'],
        ['en', 'auth', 'msg_session_expired', 'Session expired.'],
        ['en', 'nav', 'dashboard', 'Dashboard'],
        ['en', 'nav', 'panel', 'Waiter Panel'],
        ['en', 'nav', 'domicilios', 'Deliveries'],
        ['en', 'nav', 'mesas', 'Tables'],
        ['en', 'nav', 'categorias', 'Categories'],
        ['en', 'nav', 'productos', 'Products'],
        ['en', 'nav', 'ordenes', 'Orders'],
        ['en', 'nav', 'pagos', 'Payments'],
        ['en', 'nav', 'usuarios', 'Users'],
        ['en', 'nav', 'movimientos', 'Activity Log'],
        ['en', 'nav', 'configuracion', 'Settings'],
        ['en', 'nav', 'logout', 'Sign Out'],
        ['en', 'pos', 'btn_cobrar', 'Charge'],
        ['en', 'pos', 'btn_create_order', 'Create Order'],
        ['en', 'pos', 'lbl_total', 'Total'],
        ['en', 'pos', 'lbl_subtotal', 'Subtotal'],
        ['en', 'pos', 'lbl_discount', 'Discount'],
        ['en', 'pos', 'lbl_delivery_fee', 'Delivery Fee'],
        ['en', 'pos', 'lbl_cart', 'Cart'],
        ['en', 'pos', 'lbl_empty_cart', 'Empty cart'],
        ['en', 'pos', 'lbl_add', 'Add'],
        ['en', 'pos', 'lbl_remove', 'Remove'],
        ['en', 'pos', 'lbl_qty', 'Qty'],
        ['en', 'license', 'title_expired', 'License Expired'],
        ['en', 'license', 'title_no_license', 'No License'],
        ['en', 'license', 'title_revoked', 'License Revoked'],
        ['en', 'license', 'title_suspended', 'License Suspended'],
        ['en', 'license', 'title_no_connection', 'No Server Connection'],
        ['en', 'license', 'msg_expired', 'Your license has expired. Contact the provider to renew.'],
        ['en', 'license', 'msg_no_license', 'No active license on this device. Enter an activation key.'],
        ['en', 'license', 'msg_revoked', 'This license has been revoked by the administrator.'],
        ['en', 'license', 'msg_suspended', 'Your license is suspended. Contact the provider.'],
        ['en', 'license', 'msg_no_connection', 'Could not verify the license. Connect to the internet to continue using the system after 24 hours without verification.'],
        ['en', 'license', 'lbl_activation_key', 'Activation Key'],
        ['en', 'license', 'lbl_placeholder_key', 'XXXX-XXXX-XXXX-XXXX'],
        ['en', 'license', 'btn_activate', 'Activate License'],
        ['en', 'license', 'btn_buy', 'Buy License'],
        ['en', 'license', 'btn_remove', 'Remove License'],
        ['en', 'license', 'msg_activating', 'Activating...'],
        ['en', 'license', 'msg_activated', 'License activated successfully!'],
        ['en', 'license', 'msg_activation_failed', 'Failed to activate license.'],
        ['en', 'license', 'msg_enter_key', 'Enter the activation key.'],
        ['en', 'license', 'lbl_plan', 'Plan'],
        ['en', 'license', 'lbl_expires', 'Expires'],
        ['en', 'license', 'lbl_business', 'Business'],
        ['en', 'license', 'lbl_never', 'Never'],
        ['en', 'license', 'lbl_valid', 'Valid'],
        ['en', 'general', 'lbl_search', 'Search'],
        ['en', 'general', 'lbl_actions', 'Actions'],
        ['en', 'general', 'lbl_edit', 'Edit'],
        ['en', 'general', 'lbl_delete', 'Delete'],
        ['en', 'general', 'lbl_save', 'Save'],
        ['en', 'general', 'lbl_cancel', 'Cancel'],
        ['en', 'general', 'lbl_close', 'Close'],
        ['en', 'general', 'lbl_confirm', 'Confirm'],
        ['en', 'general', 'lbl_yes', 'Yes'],
        ['en', 'general', 'lbl_no', 'No'],
        ['en', 'general', 'lbl_all', 'All'],
        ['en', 'general', 'lbl_active', 'Active'],
        ['en', 'general', 'lbl_inactive', 'Inactive'],
        ['en', 'general', 'msg_saved', 'Saved successfully'],
        ['en', 'general', 'msg_deleted', 'Deleted successfully'],
        ['en', 'general', 'msg_error', 'An error occurred'],
        ['en', 'general', 'msg_no_results', 'No results'],
        ['en', 'general', 'msg_confirm_delete', 'Are you sure you item?']
    ];
    foreach ($traducciones_en as $t) $stmt->execute($t);
    
    // Seed Portuguese translations
    $traducciones_pt = [
        ['pt', 'auth', 'btn_login', 'Entrar'],
        ['pt', 'auth', 'lbl_user', 'Usuario'],
        ['pt', 'auth', 'lbl_pass', 'Senha'],
        ['pt', 'auth', 'lbl_placeholder_user', 'Seu usuario'],
        ['pt', 'auth', 'lbl_placeholder_pass', '••••••••'],
        ['pt', 'auth', 'msg_verifying', 'Verificando...'],
        ['pt', 'auth', 'msg_welcome', 'Bem-vindo,'],
        ['pt', 'auth', 'msg_invalid', 'Usuario ou senha incorretos.'],
        ['pt', 'auth', 'msg_complete_fields', 'Preencha os campos'],
        ['pt', 'nav', 'dashboard', 'Painel'],
        ['pt', 'nav', 'panel', 'Painel Garcom'],
        ['pt', 'nav', 'domicilios', 'Entregas'],
        ['pt', 'nav', 'logout', 'Sair'],
        ['pt', 'license', 'title_expired', 'Licenca Expirada'],
        ['pt', 'license', 'title_no_license', 'Sem Licenca'],
        ['pt', 'license', 'btn_activate', 'Ativar Licenca'],
        ['pt', 'license', 'btn_buy', 'Comprar Licenca']
    ];
    foreach ($traducciones_pt as $t) $stmt->execute($t);
    
    $steps[] = 'Tabla TRADUCCIONES creada con traducciones en es/en/pt (preparada para Fase 7)';

    // ============================================================
    //    SEED DEMO LICENSE
    // ============================================================
    
    // Insert a demo license that works locally for development
    $demoKey = getenv('SETUP_DEMO_LICENSE_KEY') ?: strtoupper(bin2hex(random_bytes(8)));
    $pdo->prepare('INSERT INTO LICENCIAS 
        (idLicencia, clave_activacion, negocio_nombre, negocio_nit, contacto_email, contacto_telefono, 
         plan, fecha_activacion, fecha_expiracion, estado, max_usuarios, max_mesas, modulos_habilitados) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 365 DAY), "ACTIVA", 10, 50, ?)')
        ->execute([
            'LIC-DEMO-001',
            $demoKey,
            'Freakers',
            '1214722370',
            'searpix@gmail.com',
            '3235636580',
            'ENTERPRISE',
            json_encode(['pos','domicilios','facturacion','whatsapp','dashboard','configuracion'])
        ]);
    
    // Auto-activate the demo license
    $pdo->prepare('INSERT INTO LICENCIA_ACTIVA (idLicencia, hardware_id, activated_at, last_check) VALUES (?, ?, NOW(), NOW())')
        ->execute(['LIC-DEMO-001', 'DEV-MACHINE']);
    
    // Save demo key in config
    $pdo->prepare('INSERT INTO CONFIGURACION (parametro, valor) VALUES ("LICENCIA_CLAVE", ?) ON DUPLICATE KEY UPDATE valor = ?')
        ->execute([$demoKey, $demoKey]);
    
    $steps[] = 'Licencia inicial generada de forma aleatoria para instalacion local.';

    // ===== Save DB config =====
    // Se genera un db_config.php COMPLETO: ademas de la conexion incluye
    // POS_SECRET (necesario por config.php) y demas constantes, de modo que
    // el POS siga funcionando aunque no exista el archivo .env.
    $posSecret = (defined('POS_SECRET') && POS_SECRET !== '') ? POS_SECRET : bin2hex(random_bytes(32));
    $tenantId  = (defined('TENANT_ID') && TENANT_ID !== '') ? TENANT_ID : 'template';
    $setupTok  = defined('SETUP_TOKEN') ? SETUP_TOKEN : '';
    $centralUrl = defined('CENTRAL_API_URL') ? CENTRAL_API_URL : '';
    $tenantKey  = defined('TENANT_API_KEY') ? TENANT_API_KEY : '';
    $configContent  = "<?php\n";
    $configContent .= "/* Auto-generated by setup.php */\n";
    $configContent .= "define('DB_HOST', 'localhost');\n";
    $configContent .= "define('DB_NAME', 'freakers_pos');\n";
    $configContent .= "define('DB_USER', 'root');\n";
    $configContent .= "define('DB_PASS', '');\n";
    $configContent .= "define('POS_SECRET', '" . addslashes($posSecret) . "');\n";
    $configContent .= "define('TENANT_ID', '" . addslashes($tenantId) . "');\n";
    $configContent .= "define('CENTRAL_API_URL', '" . addslashes($centralUrl) . "');\n";
    $configContent .= "define('TENANT_API_KEY', '" . addslashes($tenantKey) . "');\n";
    $configContent .= "define('SETUP_TOKEN', '" . addslashes($setupTok) . "');\n";
    $configContent .= "define('SETUP_DB_HOST', 'localhost');\n";
    $configContent .= "define('SETUP_DB_USER', 'root');\n";
    $configContent .= "define('SETUP_DB_PASS', '');\n";
    file_put_contents(__DIR__ . '/db_config.php', $configContent);
    $steps[] = 'Archivo db_config.php generado (incluye POS_SECRET)';

    // ===== VERIFICATION =====
    $verifications = [];
    
    $tables = ['USUARIOS','MESAS','CATEGORIAS','PRODUCTOS','ORDENES','DETALLE_ORDEN','PAGOS','MOVIMIENTOS','CONFIGURACION','APPS_DELIVERY','RESPUESTAS_RAPIDAS','TICKET_CONFIG','NOTIFICACIONES','LICENCIAS','LICENCIA_ACTIVA','MESAS_UNIDAS','FACTURAS','FACTURA_DISENO','IDIOMAS','TRADUCCIONES'];
    foreach ($tables as $t) {
        try {
            $cnt = $pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
            $verifications[] = $t . ': ' . $cnt . ' registros';
        } catch (Exception $e) {
            $verifications[] = $t . ': ERROR - ' . $e->getMessage();
        }
    }
    
    // Test login
    $stmt = $pdo->prepare('SELECT * FROM USUARIOS WHERE usuario = ? AND estado = ?');
    $stmt->execute(['SEARPIX', 'ACTIVO']);
    $user = $stmt->fetch();
    $verifications[] = 'Login test SEARPIX: ' . ($user && password_verify($adminPassword, $user['clave']) ? 'OK' : 'FALLIDO');
    
    // Test license
    $stmt = $pdo->query('SELECT l.idLicencia, l.clave_activacion, l.estado, l.plan, l.negocio_nombre, la.last_check FROM LICENCIAS l JOIN LICENCIA_ACTIVA la ON l.idLicencia = la.idLicencia LIMIT 1');
    $licTest = $stmt->fetch();
    $verifications[] = 'Licencia test: ' . ($licTest ? $licTest['plan'] . ' / ' . $licTest['estado'] . ' / ' . $licTest['negocio_nombre'] : 'NO ENCONTRADA');

    // ===== OUTPUT =====
    echo '<!DOCTYPE html><html><head><title>Comandix v6 - Setup</title>';
    echo '<style>';
    echo '*{margin:0;padding:0;box-sizing:border-box}';
    echo 'body{font-family:"Inter",monospace;background:#0B0F19;color:#e0e0e0;padding:30px;min-height:100vh}';
    echo 'h1{font-size:1.6rem;font-weight:900;background:linear-gradient(135deg,#6C3CE1,#E94560);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:6px}';
    echo '.ver{font-size:0.82rem;color:rgba(255,255,255,0.35);margin-bottom:20px}';
    echo 'h2{font-size:1.05rem;font-weight:700;color:#e94560;margin:18px 0 8px}';
    echo '.step{padding:8px 14px;margin:4px 0;border-radius:8px;background:rgba(74,222,128,0.08);border-left:3px solid #4ade80;font-size:0.82rem}';
    echo '.step.err{background:rgba(239,68,68,0.08);border-left-color:#ef4444}';
    echo '.verify{padding:8px 14px;margin:4px 0;border-radius:8px;background:rgba(56,189,248,0.08);border-left:3px solid #38bdf8;font-size:0.82rem}';
    echo '.success{text-align:center;padding:24px;margin:20px 0;border-radius:16px;background:linear-gradient(135deg,rgba(108,60,225,0.15),rgba(74,222,128,0.08));border:1px solid rgba(108,60,225,0.3)}';
    echo '.success h2{color:#4ade80;font-size:1.2rem}';
    echo 'a.btn{display:inline-block;padding:12px 28px;border-radius:12px;background:#6c3ce1;color:#fff;text-decoration:none;font-weight:700;font-size:0.95rem;margin-top:12px;transition:transform 0.2s}';
    echo 'a.btn:hover{transform:scale(1.05)}';
    echo 'a.btn2{background:#e94560;margin-left:8px}';
    echo '</style></head><body>';
    
    echo '<h1>⚡ Comandix v6 — Setup Completo</h1>';
    echo '<div class="ver">Incluye: Sistema de Licencias + Tablas Futuras (Mesas Unidas, Facturas, Idiomas)</div>';
    
    echo '<h2>Pasos ejecutados</h2>';
    foreach ($steps as $s) echo '<div class="step">' . $s . '</div>';
    
    if (!empty($errors)) {
        echo '<h2>Errores</h2>';
        foreach ($errors as $e) echo '<div class="step err">' . $e . '</div>';
    }
    
    echo '<h2>Verificacion</h2>';
    foreach ($verifications as $v) echo '<div class="verify">' . $v . '</div>';
    
    echo '<div class="success">';
    echo '<h2>✅ Setup Completado!</h2>';
    echo '<p style="margin:10px 0;font-size:0.88rem;color:rgba(255,255,255,0.6)">20 tablas creadas. Todos los datos de demo insertados. Licencia DEMO activa.</p>';
    echo '<p style="margin:6px 0;font-size:0.78rem;color:rgba(255,255,255,0.4)">Credenciales definidas por el operador; no se imprimen por seguridad.</p>';
    echo '<a class="btn" href="../login.html">Ir al Login &rarr;</a>';
    echo '<a class="btn btn2" href="../licencia-expirada.html">Ver Pantalla Licencia &rarr;</a>';
    echo '</div>';
    
    echo '</body></html>';

} catch (Exception $e) {
    echo '<div class="step err">Error critico: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '<div class="step err">Archivo: ' . $e->getFile() . ' linea ' . $e->getLine() . '</div>';
}
