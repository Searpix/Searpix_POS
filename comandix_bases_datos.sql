-- ============================================================
--  Comandix / Searpix  —  SCRIPT COMPLETO DE CREACION DE BASES DE DATOS
--  Crea las 2 bases que necesita el proyecto:
--    1) freakers_licenses  -> servidor de licencias (carpeta raiz /)
--    2) freakers_pos        -> sistema POS + plantilla multi-tenant (/systems)
--
--  COMO USARLO (XAMPP):
--    Opcion A (phpMyAdmin): pestana "SQL" -> pega TODO este archivo -> Continuar.
--    Opcion B (consola):    mysql -u root < comandix_bases_datos.sql
--
--  Usuarios y contrasenas iniciales que deja creados:
--    - Licencias  (login admin):  usuario  searpix   /  Comandix2026*
--    - POS admin  (login):        usuario  SEARPIX   /  Comandix2026*
--    - POS mesero (login):        usuario  FREAKERS  /  Freakers2026*
--    (cambialas despues desde cada panel)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '-05:00';

-- ============================================================
--  BASE 1/2 :  freakers_licenses
-- ============================================================
DROP DATABASE IF EXISTS `freakers_licenses`;
CREATE DATABASE `freakers_licenses` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `freakers_licenses`;

-- 1. ADMIN_USUARIOS
CREATE TABLE ADMIN_USUARIOS (
    idAdmin VARCHAR(50) PRIMARY KEY,
    usuario VARCHAR(80) NOT NULL UNIQUE,
    clave VARCHAR(255) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    email VARCHAR(200) DEFAULT NULL,
    rol ENUM('SUPER','ADMIN','SOPORTE') NOT NULL DEFAULT 'ADMIN',
    estado ENUM('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
    fechaCreacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    ultimoLogin DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ADMIN_USUARIOS (idAdmin, usuario, clave, nombre, email, rol, estado) VALUES
('ADM-001', 'searpix', '$2y$10$rmexJ6td8wkGskTqlVtAoODD6zhEXwjMGRR25zhkFcxfbiKfqEhB6', 'SearPix', 'searpix@gmail.com', 'SUPER', 'ACTIVO');

-- 2. CLIENTES
CREATE TABLE CLIENTES (
    idCliente VARCHAR(50) PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    apellido VARCHAR(200) DEFAULT NULL,
    email VARCHAR(200) NOT NULL UNIQUE,
    telefono VARCHAR(50) DEFAULT NULL,
    empresa VARCHAR(200) DEFAULT NULL,
    nit VARCHAR(50) DEFAULT NULL,
    pais VARCHAR(100) DEFAULT 'Colombia',
    ciudad VARCHAR(100) DEFAULT NULL,
    clave VARCHAR(255) NOT NULL,
    estado ENUM('ACTIVO','INACTIVO','SUSPENDIDO') NOT NULL DEFAULT 'ACTIVO',
    fechaRegistro DATETIME DEFAULT CURRENT_TIMESTAMP,
    ultimoLogin DATETIME DEFAULT NULL,
    whatsapp VARCHAR(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. PLANES_LICENCIA
CREATE TABLE PLANES_LICENCIA (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO PLANES_LICENCIA VALUES
('PLAN-BAS','Básico','Ideal para restaurantes pequeños que inician su transformación digital. Incluye lo esencial para gestionar tu menú y ventas.',49900,499900,3,15,1,'["pos","categorias","productos","ordenes","pagos"]',0,1,1,'#38BDF8','🚀'),
('PLAN-PRO','Profesional','Para restaurantes en crecimiento que necesitan domicilios, múltiples usuarios y control completo de su operación.',89900,899900,8,40,2,'["pos","domicilios","categorias","productos","ordenes","pagos","usuarios","dashboard","ticket_config","delivery_apps"]',1,1,2,'#A855F7','⚡'),
('PLAN-ENT','Enterprise','Sin límites. Para cadenas y restaurantes que quieren todo: WhatsApp integrado, facturación, multi-sucursal y soporte prioritario.',149900,1499900,50,200,10,'["pos","domicilios","categorias","productos","ordenes","pagos","usuarios","movimientos","dashboard","configuracion","ticket_config","delivery_apps","whatsapp","facturacion","mesas_unidas","notificaciones","multi_idioma"]',0,1,3,'#E94560','👑');

-- 4. LICENCIAS
CREATE TABLE LICENCIAS (
    idLicencia VARCHAR(50) PRIMARY KEY,
    clave_activacion VARCHAR(255) NOT NULL UNIQUE,
    idCliente VARCHAR(50) NOT NULL,
    idPlan VARCHAR(50) NOT NULL,
    negocio_nombre VARCHAR(200) NOT NULL,
    negocio_nit VARCHAR(50) DEFAULT NULL,
    contacto_nombre VARCHAR(200) DEFAULT NULL,
    contacto_email VARCHAR(200) DEFAULT NULL,
    contacto_telefono VARCHAR(50) DEFAULT NULL,
    plan ENUM('BASICO','PROFESIONAL','ENTERPRISE') NOT NULL DEFAULT 'BASICO',
    duracion_meses INT NOT NULL DEFAULT 12,
    fecha_activacion DATETIME DEFAULT NULL,
    fecha_expiracion DATETIME DEFAULT NULL,
    estado ENUM('ACTIVA','SUSPENDIDA','EXPIRADA','REVOCADA','PENDIENTE') NOT NULL DEFAULT 'PENDIENTE',
    max_usuarios INT DEFAULT 5,
    max_mesas INT DEFAULT 20,
    modulos_habilitados JSON DEFAULT NULL,
    hardware_id VARCHAR(255) DEFAULT NULL,
    activaciones INT DEFAULT 0,
    max_activaciones INT DEFAULT 1,
    motivo_estado TEXT DEFAULT NULL,
    idTenant VARCHAR(64) DEFAULT NULL UNIQUE,
    db_tenant VARCHAR(120) DEFAULT NULL,
    url_instancia VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (idCliente) REFERENCES CLIENTES(idCliente) ON DELETE CASCADE,
    FOREIGN KEY (idPlan) REFERENCES PLANES_LICENCIA(idPlan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. LICENCIA_ACTIVACIONES
CREATE TABLE LICENCIA_ACTIVACIONES (
    id INT AUTO_INCREMENT PRIMARY KEY,
    idLicencia VARCHAR(50) NOT NULL,
    hardware_id VARCHAR(255) NOT NULL,
    hostname VARCHAR(200) DEFAULT NULL,
    platform VARCHAR(100) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_check DATETIME DEFAULT NULL,
    estado ENUM('ACTIVA','REMOVIDA','BLOQUEADA') DEFAULT 'ACTIVA',
    FOREIGN KEY (idLicencia) REFERENCES LICENCIAS(idLicencia) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. LICENCIA_LOG
CREATE TABLE LICENCIA_LOG (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. PAGOS
CREATE TABLE PAGOS (
    idPago VARCHAR(50) PRIMARY KEY,
    idCliente VARCHAR(50) NOT NULL,
    idLicencia VARCHAR(50) DEFAULT NULL,
    idPlan VARCHAR(50) DEFAULT NULL,
    monto DECIMAL(12,2) NOT NULL,
    moneda VARCHAR(10) DEFAULT 'COP',
    metodo ENUM('TRANSFERENCIA','NEQUI','DAVIPLATA','TARJETA','EFECTIVO','WOMPI','PAYU','PAYPAL') DEFAULT 'TRANSFERENCIA',
    referencia VARCHAR(200) DEFAULT NULL,
    gateway_ref VARCHAR(255) DEFAULT NULL,
    comprobante TEXT DEFAULT NULL,
    estado ENUM('PENDIENTE','APROBADO','RECHAZADO','REEMBOLSADO') DEFAULT 'PENDIENTE',
    fecha_pago DATETIME DEFAULT NULL,
    fecha_verificacion DATETIME DEFAULT NULL,
    verificado_por VARCHAR(50) DEFAULT NULL,
    notas TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (idCliente) REFERENCES CLIENTES(idCliente) ON DELETE CASCADE,
    FOREIGN KEY (idLicencia) REFERENCES LICENCIAS(idLicencia) ON DELETE SET NULL,
    FOREIGN KEY (idPlan) REFERENCES PLANES_LICENCIA(idPlan),
    FOREIGN KEY (verificado_por) REFERENCES ADMIN_USUARIOS(idAdmin) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. TENANTS
CREATE TABLE TENANTS (
    idTenant VARCHAR(64) PRIMARY KEY,
    idLicencia VARCHAR(64) NOT NULL UNIQUE,
    db_name VARCHAR(120) NOT NULL UNIQUE,
    db_user VARCHAR(120) NOT NULL UNIQUE,
    db_host VARCHAR(255) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    api_key_hash CHAR(64) NOT NULL,
    estado ENUM('ACTIVO','SUSPENDIDO','ERROR') NOT NULL DEFAULT 'ACTIVO',
    provisioned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_status(estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. TICKETS / TICKET_MENSAJES / SESIONES / LOGIN_RATE_LIMIT
CREATE TABLE TICKETS (
    idTicket VARCHAR(50) PRIMARY KEY,
    idCliente VARCHAR(50) DEFAULT NULL,
    idTenant VARCHAR(64) DEFAULT NULL,
    tenantUserId VARCHAR(64) DEFAULT NULL,
    tenantUserName VARCHAR(200) DEFAULT NULL,
    origen VARCHAR(20) NOT NULL DEFAULT 'PORTAL',
    asunto VARCHAR(200) NOT NULL,
    categoria VARCHAR(50) DEFAULT 'GENERAL',
    prioridad ENUM('BAJA','NORMAL','ALTA','URGENTE') DEFAULT 'NORMAL',
    estado ENUM('ABIERTO','EN_PROCESO','RESUELTO') DEFAULT 'ABIERTO',
    escalado TINYINT(1) DEFAULT 0,
    creado DATETIME DEFAULT CURRENT_TIMESTAMP,
    actualizado DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cliente(idCliente),
    INDEX idx_tenant(idTenant,tenantUserId),
    INDEX idx_estado_actualizado(estado,actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE TICKET_MENSAJES (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    idTicket VARCHAR(50) NOT NULL,
    autorTipo VARCHAR(20) NOT NULL,
    autorId VARCHAR(64) NOT NULL,
    mensaje TEXT NULL,
    adjunto_url VARCHAR(300) DEFAULT NULL,
    adjunto_nombre VARCHAR(200) DEFAULT NULL,
    adjunto_tipo VARCHAR(100) DEFAULT NULL,
    creado DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_creado(idTicket,creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE SESIONES (
    jti CHAR(32) PRIMARY KEY,
    tipo VARCHAR(20) NOT NULL,
    idUsuario VARCHAR(64) NOT NULL,
    expira DATETIME NOT NULL,
    creado DATETIME DEFAULT CURRENT_TIMESTAMP,
    revocado DATETIME DEFAULT NULL,
    INDEX idx_sesion_user(tipo,idUsuario),
    INDEX idx_sesion_expira(expira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE LOGIN_RATE_LIMIT (
    scope_hash CHAR(64) PRIMARY KEY,
    intentos INT NOT NULL DEFAULT 0,
    ventana DATETIME NOT NULL,
    bloqueado_hasta DATETIME DEFAULT NULL,
    INDEX idx_bloqueo(bloqueado_hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. CONFIGURACION
CREATE TABLE CONFIGURACION (
    parametro VARCHAR(100) PRIMARY KEY,
    valor TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO CONFIGURACION (parametro, valor) VALUES
('NOMBRE_EMPRESA','Comandix'),
('SITIO_WEB','https://searpox.com'),
('WHATSAPP_VENTAS','573235636580'),
('WHATSAPP_SOPORTE','573235636580'),
('EMAIL_CONTACTO','searpix@gmail.com'),
('MONEDA','COP'),
('IMPUESTO','0'),
('PASARELA_PAGO','WOMPI'),
('WOMPI_PUBLIC_KEY',''),
('WOMPI_SECRET_KEY',''),
('ACTIVACION_AUTOMATICA','NO'),
('DIAS_PRUEBA','7'),
('LOGO_URL','https://i.ibb.co/39bHjfNc/freakers-png.png');

-- ============================================================
--  BASE 2/2 :  freakers_pos   (POS + plantilla multi-tenant)
-- ============================================================
DROP DATABASE IF EXISTS `freakers_pos`;
CREATE DATABASE `freakers_pos` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `freakers_pos`;

-- 1. USUARIOS
CREATE TABLE USUARIOS (
    idUsuario VARCHAR(50) PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    clave VARCHAR(255) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) DEFAULT NULL,
    rol ENUM('ADMIN','MESERO') NOT NULL DEFAULT 'MESERO',
    estado ENUM('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
    fechaCreacion DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO USUARIOS (idUsuario, usuario, clave, nombre, apellido, rol, estado) VALUES
('U001','SEARPIX','$2y$10$uItDsCZKosvFZfnMN2pwb.1TnmC8dDCcjYZp71bWox7c7Td.LJTAC','Diego',NULL,'ADMIN','ACTIVO'),
('U002','FREAKERS','$2y$10$.CTalTCA0WwyiatyO.Q/yOgIfPswWn7Oy5.yuZRbGcOMOWqUL5a9S','Freakers',NULL,'MESERO','ACTIVO');

-- 2. MESAS
CREATE TABLE MESAS (
    idMesa VARCHAR(20) PRIMARY KEY,
    numero VARCHAR(20) NOT NULL,
    capacidad INT NOT NULL DEFAULT 4,
    estado ENUM('LIBRE','OCUPADA','INACTIVA') NOT NULL DEFAULT 'LIBRE',
    clase ENUM('BARRA','VIP','MESAS') NOT NULL DEFAULT 'MESAS'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO MESAS VALUES
('M1','1',4,'LIBRE','MESAS'),('M2','2',4,'LIBRE','MESAS'),('M3','3',4,'LIBRE','MESAS'),('M4','4',4,'LIBRE','MESAS'),
('M5','5',4,'LIBRE','MESAS'),('M6','6',4,'LIBRE','MESAS'),('M7','7',4,'LIBRE','MESAS'),('M8','8',4,'LIBRE','MESAS'),
('M9','9',4,'LIBRE','MESAS'),('M10','10',4,'LIBRE','MESAS'),('M11','11',4,'LIBRE','MESAS'),('M12','12',4,'OCUPADA','MESAS'),
('M13','13',4,'LIBRE','MESAS'),
('VIP1','VIP 1',2,'LIBRE','VIP'),('VIP2','VIP 2',2,'LIBRE','VIP'),('VIP3','VIP 3',2,'LIBRE','VIP'),
('BARRA1','BARRA 1',1,'LIBRE','BARRA'),('BARRA2','BARRA 2',1,'LIBRE','BARRA'),('BARRA3','BARRA 3',1,'LIBRE','BARRA'),('BARRA4','BARRA 4',1,'LIBRE','BARRA');

-- 3. CATEGORIAS
CREATE TABLE CATEGORIAS (
    idCategoria VARCHAR(50) PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    imagen TEXT DEFAULT NULL,
    estado ENUM('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO CATEGORIAS VALUES
('CAT-ENT','Entradas','https://cdn-icons-png.flaticon.com/512/5306/5306580.png','ACTIVO'),
('CAT-BEB','Bebidas','https://cdn-icons-png.flaticon.com/512/2391/2391701.png','ACTIVO'),
('CAT-GAS','Gaseosas','https://cdn-icons-png.flaticon.com/512/3050/3050130.png','ACTIVO'),
('CAT-JUG','Jugos Naturales','https://cdn-icons-png.flaticon.com/512/2442/2442019.png','ACTIVO'),
('CAT-LIM','Limonadas',NULL,'ACTIVO'),
('CAT-CER','Cervezas','https://cdn-icons-png.flaticon.com/512/3728/3728021.png','ACTIVO'),
('CAT-MIC','Micheladas','https://cdn-icons-png.flaticon.com/512/7924/7924161.png','ACTIVO'),
('CAT-COC','Cocteles','https://cdn-icons-png.flaticon.com/512/7285/7285889.png','ACTIVO'),
('CAT-HAM','Hamburguesas','https://cdn-icons-png.flaticon.com/512/3075/3075977.png','ACTIVO'),
('CAT-BAB','Baby Burgers','https://i.ibb.co/whWMcfhq/images.png','ACTIVO'),
('CAT-HF','HamFreaks','https://cdn-icons-png.flaticon.com/512/2278/2278992.png','ACTIVO'),
('CAT-ESP','Especiales','https://i.ibb.co/39bHjfNc/freakers-png.png','ACTIVO'),
('CAT-PER','Perros','https://cdn-icons-png.flaticon.com/512/2674/2674083.png','ACTIVO'),
('CAT-PAP','Papas','https://cdn-icons-png.flaticon.com/512/1057/1057356.png','ACTIVO'),
('CAT-ADI','Adiciones','https://cdn-icons-png.flaticon.com/512/9224/9224691.png','ACTIVO');

-- 4. PRODUCTOS
CREATE TABLE PRODUCTOS (
    idProducto VARCHAR(50) PRIMARY KEY,
    idCategoria VARCHAR(50) NOT NULL,
    nombre VARCHAR(200) NOT NULL,
    precio DECIMAL(12,2) NOT NULL DEFAULT 0,
    imagen TEXT DEFAULT NULL,
    estado ENUM('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
    FOREIGN KEY (idCategoria) REFERENCES CATEGORIAS(idCategoria) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO PRODUCTOS VALUES
('PROD-001','CAT-ENT','Nachos con Guacamole',8000,'https://i.ibb.co/39bHjfNc/freakers-png.png','ACTIVO'),
('PROD-002','CAT-ENT','Patacones con Guacamole',8000,'https://cdn-icons-png.flaticon.com/512/5306/5306580.png','ACTIVO'),
('PROD-003','CAT-ENT','Quesadilla x3',10000,NULL,'ACTIVO'),
('PROD-004','CAT-ENT','Patacones con Carne x6',15000,NULL,'ACTIVO'),
('PROD-005','CAT-BEB','Frape Colombiano',12000,NULL,'ACTIVO'),
('PROD-006','CAT-GAS','Coca-Cola 400ml',5000,NULL,'ACTIVO'),
('PROD-007','CAT-LIM','Limonada de Coco',9000,NULL,'ACTIVO'),
('PROD-008','CAT-LIM','Limonada de Cereza',9000,NULL,'ACTIVO'),
('PROD-009','CAT-LIM','Limonada Mango Biche',9000,NULL,'ACTIVO'),
('PROD-010','CAT-LIM','Limonada Natural',7000,NULL,'ACTIVO'),
('PROD-011','CAT-JUG','Jugo Natural en Agua: Fresa',6000,NULL,'ACTIVO'),
('PROD-012','CAT-JUG','Jugo Natural en Agua: Mora',6000,NULL,'ACTIVO'),
('PROD-013','CAT-JUG','Jugo Natural en Agua: Mango',6000,NULL,'ACTIVO'),
('PROD-014','CAT-JUG','Jugo Natural en Agua: Maracuya',6000,NULL,'ACTIVO'),
('PROD-015','CAT-JUG','Jugo Natural en Agua: Mandarina',6000,NULL,'ACTIVO'),
('PROD-016','CAT-JUG','Jugo Natural en Agua: Lulo',6000,NULL,'ACTIVO'),
('PROD-017','CAT-JUG','Jugo Natural en Leche: Fresa',8000,NULL,'ACTIVO'),
('PROD-018','CAT-JUG','Jugo Natural en Leche: Mora',8000,NULL,'ACTIVO'),
('PROD-019','CAT-JUG','Jugo Natural en Leche: Mango',8000,NULL,'ACTIVO'),
('PROD-020','CAT-JUG','Jugo Natural en Leche: Maracuya',8000,NULL,'ACTIVO'),
('PROD-021','CAT-CER','Cerveza Aguila',4000,NULL,'ACTIVO'),
('PROD-022','CAT-CER','Cerveza Poker',4000,NULL,'ACTIVO'),
('PROD-023','CAT-CER','Cerveza Club Colombia',5000,NULL,'ACTIVO'),
('PROD-024','CAT-CER','Cerveza Corona',8000,NULL,'ACTIVO'),
('PROD-025','CAT-MIC','Michelada Clasica',12000,NULL,'ACTIVO'),
('PROD-026','CAT-MIC','Michelada de Mango',14000,NULL,'ACTIVO'),
('PROD-027','CAT-MIC','Michelada de Fresa',14000,NULL,'ACTIVO'),
('PROD-028','CAT-COC','Coco Loco',18000,NULL,'ACTIVO'),
('PROD-029','CAT-COC','Margarita',16000,NULL,'ACTIVO'),
('PROD-030','CAT-HAM','Hamburguesa Clasica',18000,NULL,'ACTIVO'),
('PROD-031','CAT-HAM','Hamburguesa Doble',25000,NULL,'ACTIVO'),
('PROD-032','CAT-HAM','Hamburguesa BBQ',22000,NULL,'ACTIVO'),
('PROD-033','CAT-BAB','Baby Burger Sencilla',10000,NULL,'ACTIVO'),
('PROD-034','CAT-BAB','Baby Burger Queso',12000,NULL,'ACTIVO'),
('PROD-035','CAT-HF','HamFreaks Clasica',30000,'https://cdn-icons-png.flaticon.com/512/2278/2278992.png','ACTIVO'),
('PROD-036','CAT-HF','HamFreaks Doble',40000,NULL,'ACTIVO'),
('PROD-037','CAT-ESP','Freakers Especial',35000,'https://i.ibb.co/39bHjfNc/freakers-png.png','ACTIVO'),
('PROD-038','CAT-PER','Perro Sencillo',8000,NULL,'ACTIVO'),
('PROD-039','CAT-PER','Perro Doble',12000,NULL,'ACTIVO'),
('PROD-040','CAT-PER','Perro Choripapa',15000,NULL,'ACTIVO'),
('PROD-041','CAT-PAP','Papas Clasicas',8000,NULL,'ACTIVO'),
('PROD-042','CAT-PAP','Papas Cheddar',10000,NULL,'ACTIVO'),
('PROD-043','CAT-PAP','Papas Cargadas',14000,NULL,'ACTIVO'),
('PROD-044','CAT-ADI','Queso Extra',3000,NULL,'ACTIVO'),
('PROD-045','CAT-ADI','Carne Extra',5000,NULL,'ACTIVO'),
('PROD-046','CAT-ADI','Tocineta Extra',3000,NULL,'ACTIVO'),
('PROD-047','CAT-ADI','Huevo Extra',2000,NULL,'ACTIVO');

-- 5. ORDENES
CREATE TABLE ORDENES (
    idOrden VARCHAR(50) PRIMARY KEY,
    idMesa VARCHAR(20) NOT NULL,
    idUsuario VARCHAR(50) DEFAULT NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('PENDIENTE','ACTIVA','PAGADO','EN_PREPARACION','EN_CAMINO','ENTREGADO','COMPLETADO','CANCELADO','ELIMINADA') NOT NULL DEFAULT 'PENDIENTE',
    descuento DECIMAL(12,2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    egreso DECIMAL(12,2) NOT NULL DEFAULT 0,
    comentario TEXT DEFAULT NULL,
    canal VARCHAR(50) DEFAULT 'LOCAL',
    idApp VARCHAR(50) DEFAULT NULL,
    cliente_nombre VARCHAR(200) DEFAULT NULL,
    cliente_telefono VARCHAR(50) DEFAULT NULL,
    cliente_direccion TEXT DEFAULT NULL,
    domicilio DECIMAL(12,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (idUsuario) REFERENCES USUARIOS(idUsuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. DETALLE_ORDEN
CREATE TABLE DETALLE_ORDEN (
    idDetalle VARCHAR(80) PRIMARY KEY,
    idOrden VARCHAR(50) NOT NULL,
    idProducto VARCHAR(50) NOT NULL,
    cantidad INT NOT NULL DEFAULT 1,
    precioUnitario DECIMAL(12,2) NOT NULL DEFAULT 0,
    totalLinea DECIMAL(12,2) NOT NULL DEFAULT 0,
    producto VARCHAR(200) DEFAULT NULL,
    FOREIGN KEY (idOrden) REFERENCES ORDENES(idOrden) ON DELETE CASCADE,
    FOREIGN KEY (idProducto) REFERENCES PRODUCTOS(idProducto) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. PAGOS
CREATE TABLE PAGOS (
    idPago VARCHAR(50) PRIMARY KEY,
    idOrden VARCHAR(50) NOT NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metodo VARCHAR(50) NOT NULL,
    monto DECIMAL(12,2) NOT NULL DEFAULT 0,
    idUsuario VARCHAR(50) DEFAULT NULL,
    FOREIGN KEY (idOrden) REFERENCES ORDENES(idOrden) ON DELETE CASCADE,
    FOREIGN KEY (idUsuario) REFERENCES USUARIOS(idUsuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. MOVIMIENTOS
CREATE TABLE MOVIMIENTOS (
    idMovimiento VARCHAR(50) PRIMARY KEY,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    idUsuario VARCHAR(50) DEFAULT NULL,
    accion VARCHAR(50) NOT NULL,
    idReferencia VARCHAR(50) DEFAULT NULL,
    descripcion TEXT DEFAULT NULL,
    FOREIGN KEY (idUsuario) REFERENCES USUARIOS(idUsuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. CONFIGURACION
CREATE TABLE CONFIGURACION (
    parametro VARCHAR(100) PRIMARY KEY,
    valor TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO CONFIGURACION (parametro, valor) VALUES
('NOMBRE_NEGOCIO','Freakers'),
('NOMBRE_RESTAURANTE','FREAKERS'),
('RAZON_SOCIAL','BURGERS AND FAST FOOD'),
('NIT','1214722370'),
('DIRECCION','Medellin, Colombia'),
('TELEFONO','310 574 3129'),
('SUBTITULO','Burgers & Fast Food'),
('LOGO','https://i.ibb.co/39bHjfNc/freakers-png.png'),
('COLOR_PRIMARIO','#6C3CE1'),
('COLOR_SECUNDARIO','#1A1A2E'),
('COLOR_ACENTO','#E94560'),
('COLOR_FONDO','#16213E'),
('TIPOGRAFIA','Inter'),
('VALOR_DOMICILIO','5000'),
('AUTO_IMPRIMIR','SI'),
('MONEDA','COP'),
('IMPUESTO','0'),
('PEDIDOS_COCINA','SI'),
('WHATSAPP_NUMERO',''),
('WHATSAPP_API_TOKEN',''),
('WHATSAPP_WABA_ID',''),
('MODO_TEMA','oscuro'),
('ANIMACIONES_ACTIVAS','SI'),
('IDIOMA_PRINCIPAL','es'),
('IDIOMAS_DISPONIBLES','["es","en","pt"]'),
('LICENCIA_SERVER',''),
('LICENCIA_CLAVE','');

-- 10. APPS_DELIVERY
CREATE TABLE APPS_DELIVERY (
    idApp VARCHAR(50) PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    logo TEXT DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    comision DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    credencial VARCHAR(255) DEFAULT NULL,
    orden INT DEFAULT 0,
    webhook_url VARCHAR(500) DEFAULT NULL,
    api_key VARCHAR(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO APPS_DELIVERY VALUES
('APP-WHATSAPP','WhatsApp','https://cdn-icons-png.flaticon.com/512/1240/1240979.png',1,0.00,NULL,1,NULL,NULL),
('APP-DIDI','DidiFood','https://cdn-icons-png.flaticon.com/512/2920/2920349.png',1,20.00,NULL,2,NULL,NULL),
('APP-UBER','UberEats','https://cdn-icons-png.flaticon.com/512/3490/3490285.png',1,25.00,NULL,3,NULL,NULL),
('APP-RAPPI','Rappi','https://cdn-icons-png.flaticon.com/512/3490/3490400.png',1,22.00,NULL,4,NULL,NULL),
('APP-IFOOD','iFood','https://cdn-icons-png.flaticon.com/512/3046/3046921.png',1,18.00,NULL,5,NULL,NULL),
('APP-PEDIDOSYA','PedidosYa','https://cdn-icons-png.flaticon.com/512/3490/3490530.png',1,15.00,NULL,6,NULL,NULL);

-- 11. RESPUESTAS_RAPIDAS
CREATE TABLE RESPUESTAS_RAPIDAS (
    id VARCHAR(50) PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    mensaje TEXT NOT NULL,
    variables VARCHAR(500) DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    orden INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO RESPUESTAS_RAPIDAS VALUES
('RR-001','Confirmacion','Hola {cliente}! Tu pedido #{id_orden} ha sido confirmado. Productos: {productos}. Total: {total_domicilio}. {negocio}','{cliente},{productos},{total_domicilio},{id_orden},{negocio}',1,1),
('RR-002','En Camino','Hola {cliente}! Tu domicilio #{id_orden} ya va en camino. Llegara pronto a {direccion}. {negocio}','{cliente},{id_orden},{direccion},{negocio}',1,2),
('RR-003','Entregado','Hola {cliente}! Tu domicilio #{id_orden} fue entregado exitosamente. Gracias por pedir en {negocio}!','{cliente},{id_orden},{negocio}',1,3),
('RR-004','Cancelado','Hola {cliente}. Lamentamos informarte que tu domicilio #{id_orden} ha sido cancelado. Contactanos al {telefono}. {negocio}','{cliente},{id_orden},{telefono},{negocio}',1,4);

-- 12. TICKET_CONFIG
CREATE TABLE TICKET_CONFIG (
    id VARCHAR(50) PRIMARY KEY,
    bloque VARCHAR(50) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    contenido TEXT DEFAULT NULL,
    orden INT NOT NULL DEFAULT 0,
    opciones JSON DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO TICKET_CONFIG VALUES
('TKT-HEADER','HEADER',1,'{logo}',0,'{"alineacion":"centro","tamano":"grande"}'),
('TKT-NEGOCIO','NEGOCIO',1,'{negocio}\n{razon_social}\nNIT: {nit}\n{direccion} - {telefono}',1,'{"alineacion":"centro","tamano":"normal"}'),
('TKT-ORDEN','ORDEN',1,'Orden: {id_orden}\nFecha: {fecha}\nTipo: {tipo}\n{info_cliente}',2,'{"alineacion":"izquierda","tamano":"normal"}'),
('TKT-ITEMS','ITEMS',1,'{items_tabla}',3,'{"alineacion":"izquierda","tamano":"normal"}'),
('TKT-TOTALES','TOTALES',1,'Subtotal: {subtotal}\nDomicilio: {domicilio}\n----------------\nTOTAL: {total}',4,'{"alineacion":"derecha","tamano":"normal"}'),
('TKT-FOOTER','FOOTER',1,'Gracias por tu compra!\n{negocio}',5,'{"alineacion":"centro","tamano":"normal"}'),
('TKT-PUBLICIDAD','PUBLICIDAD',0,'Siguenos en @freakers\nInstagram: @freakersburger',6,'{"alineacion":"centro","tamano":"pequeno"}');

-- 13. NOTIFICACIONES
CREATE TABLE NOTIFICACIONES (
    idNotificacion VARCHAR(50) PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    mensaje TEXT DEFAULT NULL,
    idReferencia VARCHAR(50) DEFAULT NULL,
    leida TINYINT(1) NOT NULL DEFAULT 0,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. LICENCIAS (validacion local de licencia del POS)
CREATE TABLE LICENCIAS (
    idLicencia VARCHAR(50) PRIMARY KEY,
    clave_activacion VARCHAR(255) NOT NULL UNIQUE,
    negocio_nombre VARCHAR(200) NOT NULL,
    negocio_nit VARCHAR(50) DEFAULT NULL,
    contacto_email VARCHAR(200) DEFAULT NULL,
    contacto_telefono VARCHAR(50) DEFAULT NULL,
    plan ENUM('BASICO','PROFESIONAL','ENTERPRISE') NOT NULL DEFAULT 'BASICO',
    fecha_activacion DATETIME DEFAULT NULL,
    fecha_expiracion DATETIME DEFAULT NULL,
    estado ENUM('ACTIVA','SUSPENDIDA','EXPIRADA','REVOCADA') NOT NULL DEFAULT 'ACTIVA',
    max_usuarios INT DEFAULT 5,
    max_mesas INT DEFAULT 20,
    modulos_habilitados JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. LICENCIA_ACTIVA
CREATE TABLE LICENCIA_ACTIVA (
    id INT AUTO_INCREMENT PRIMARY KEY,
    idLicencia VARCHAR(50) NOT NULL,
    hardware_id VARCHAR(255) DEFAULT NULL,
    activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_check DATETIME DEFAULT NULL,
    check_interval INT DEFAULT 3600,
    FOREIGN KEY (idLicencia) REFERENCES LICENCIAS(idLicencia) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. MESAS_UNIDAS
CREATE TABLE MESAS_UNIDAS (
    idUnion VARCHAR(50) PRIMARY KEY,
    mesas JSON NOT NULL,
    label VARCHAR(100) NOT NULL,
    estado ENUM('ACTIVA','DISUELTA') NOT NULL DEFAULT 'ACTIVA',
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    idOrdenCompartida VARCHAR(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 17. FACTURAS
CREATE TABLE FACTURAS (
    idFactura VARCHAR(50) PRIMARY KEY,
    idOrden VARCHAR(50) DEFAULT NULL,
    numero_factura VARCHAR(50) NOT NULL UNIQUE,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tipo ENUM('ELECTRONICA','POS','SOPORTE','NOTA_CREDITO') NOT NULL DEFAULT 'POS',
    estado ENUM('BORRADOR','EMITIDA','ANULADA') NOT NULL DEFAULT 'BORRADOR',
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
    metodo_pago VARCHAR(50) DEFAULT 'EFECTIVO',
    resolucion_dian VARCHAR(100) DEFAULT NULL,
    prefijo_factura VARCHAR(20) DEFAULT NULL,
    consecutivo INT DEFAULT 0,
    qr_code TEXT DEFAULT NULL,
    cude TEXT DEFAULT NULL,
    xml_content LONGTEXT DEFAULT NULL,
    pdf_path VARCHAR(500) DEFAULT NULL,
    diseno_json JSON DEFAULT NULL,
    FOREIGN KEY (idOrden) REFERENCES ORDENES(idOrden) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 18. FACTURA_DISENO
CREATE TABLE FACTURA_DISENO (
    idDiseno VARCHAR(50) PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    bloques JSON NOT NULL,
    tamano ENUM('58mm','80mm','A4','PERSONALIZADO') DEFAULT '80mm',
    orientacion ENUM('vertical','horizontal') DEFAULT 'vertical',
    colores JSON DEFAULT NULL,
    activo TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 19. IDIOMAS
CREATE TABLE IDIOMAS (
    codigo VARCHAR(5) PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    activo TINYINT(1) DEFAULT 1,
    flag_emoji VARCHAR(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO IDIOMAS VALUES
('es','Espanol',1,'🇪🇸'),
('en','English',1,'🇺🇸'),
('pt','Portugues',1,'🇧🇷'),
('fr','Francais',0,'🇫🇷');

-- 20. TRADUCCIONES  (FK corregida: referencia IDIOMAS(codigo))
CREATE TABLE TRADUCCIONES (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_idioma VARCHAR(5) NOT NULL,
    grupo VARCHAR(50) NOT NULL,
    clave VARCHAR(200) NOT NULL,
    valor TEXT NOT NULL,
    FOREIGN KEY (codigo_idioma) REFERENCES IDIOMAS(codigo) ON DELETE CASCADE,
    UNIQUE KEY idx_trad (codigo_idioma, grupo, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO TRADUCCIONES (codigo_idioma, grupo, clave, valor) VALUES
('es','auth','btn_login','Iniciar Sesion'),
('es','auth','lbl_user','Usuario'),
('es','auth','lbl_pass','Contrasena'),
('es','auth','lbl_placeholder_user','Tu usuario'),
('es','auth','lbl_placeholder_pass','••••••••'),
('es','auth','msg_verifying','Verificando...'),
('es','auth','msg_welcome','Bienvenido,'),
('es','auth','msg_invalid','Usuario o contrasena incorrectos.'),
('es','auth','msg_complete_fields','Completa los campos'),
('es','auth','msg_session_expired','Sesion expirada.'),
('es','nav','dashboard','Dashboard'),
('es','nav','panel','Panel Mesero'),
('es','nav','domicilios','Domicilios'),
('es','nav','mesas','Mesas'),
('es','nav','categorias','Categorias'),
('es','nav','productos','Productos'),
('es','nav','ordenes','Ordenes'),
('es','nav','pagos','Pagos'),
('es','nav','usuarios','Usuarios'),
('es','nav','movimientos','Movimientos'),
('es','nav','configuracion','Configuracion'),
('es','nav','logout','Cerrar Sesion'),
('es','pos','btn_cobrar','Cobrar'),
('es','pos','btn_create_order','Crear Orden'),
('es','pos','lbl_total','Total'),
('es','pos','lbl_subtotal','Subtotal'),
('es','pos','lbl_discount','Descuento'),
('es','pos','lbl_delivery_fee','Domicilio'),
('es','pos','lbl_cart','Carrito'),
('es','pos','lbl_empty_cart','Carrito vacio'),
('es','pos','lbl_add','Agregar'),
('es','pos','lbl_remove','Quitar'),
('es','pos','lbl_qty','Cant'),
('es','license','title_expired','Licencia Expirada'),
('es','license','title_no_license','Sin Licencia'),
('es','license','title_revoked','Licencia Revocada'),
('es','license','title_suspended','Licencia Suspendida'),
('es','license','title_no_connection','Sin Conexion al Servidor'),
('es','license','msg_expired','Tu licencia ha expirado. Contacta al proveedor para renovar.'),
('es','license','msg_no_license','No hay licencia activa en este equipo. Ingresa una clave de activacion.'),
('es','license','msg_revoked','Esta licencia ha sido revocada por el administrador.'),
('es','license','msg_suspended','Tu licencia esta suspendida. Contacta al proveedor.'),
('es','license','msg_no_connection','No se pudo verificar la licencia. Conecta a internet para continuar usando el sistema despues de 24 horas sin verificacion.'),
('es','license','lbl_activation_key','Clave de Activacion'),
('es','license','lbl_placeholder_key','XXXX-XXXX-XXXX-XXXX'),
('es','license','btn_activate','Activar Licencia'),
('es','license','btn_buy','Comprar Licencia'),
('es','license','btn_remove','Remover Licencia'),
('es','license','msg_activating','Activando...'),
('es','license','msg_activated','Licencia activada exitosamente!'),
('es','license','msg_activation_failed','Error al activar la licencia.'),
('es','license','msg_enter_key','Ingresa la clave de activacion.'),
('es','license','lbl_plan','Plan'),
('es','license','lbl_expires','Expira'),
('es','license','lbl_business','Negocio'),
('es','license','lbl_never','Nunca'),
('es','license','lbl_valid','Valida'),
('es','general','lbl_search','Buscar'),
('es','general','lbl_actions','Acciones'),
('es','general','lbl_edit','Editar'),
('es','general','lbl_delete','Eliminar'),
('es','general','lbl_save','Guardar'),
('es','general','lbl_cancel','Cancelar'),
('es','general','lbl_close','Cerrar'),
('es','general','lbl_confirm','Confirmar'),
('es','general','lbl_yes','Si'),
('es','general','lbl_no','No'),
('es','general','lbl_all','Todos'),
('es','general','lbl_active','Activo'),
('es','general','lbl_inactive','Inactivo'),
('es','general','msg_saved','Guardado exitosamente'),
('es','general','msg_deleted','Eliminado exitosamente'),
('es','general','msg_error','Ocurrio un error'),
('es','general','msg_no_results','Sin resultados'),
('es','general','msg_confirm_delete','Estas seguro de eliminar este elemento?');

INSERT INTO TRADUCCIONES (codigo_idioma, grupo, clave, valor) VALUES
('en','auth','btn_login','Sign In'),
('en','auth','lbl_user','Username'),
('en','auth','lbl_pass','Password'),
('en','auth','lbl_placeholder_user','Your username'),
('en','auth','lbl_placeholder_pass','••••••••'),
('en','auth','msg_verifying','Verifying...'),
('en','auth','msg_welcome','Welcome,'),
('en','auth','msg_invalid','Incorrect username or password.'),
('en','auth','msg_complete_fields','Fill in the fields'),
('en','auth','msg_session_expired','Session expired.'),
('en','nav','dashboard','Dashboard'),
('en','nav','panel','Waiter Panel'),
('en','nav','domicilios','Deliveries'),
('en','nav','mesas','Tables'),
('en','nav','categorias','Categories'),
('en','nav','productos','Products'),
('en','nav','ordenes','Orders'),
('en','nav','pagos','Payments'),
('en','nav','usuarios','Users'),
('en','nav','movimientos','Activity Log'),
('en','nav','configuracion','Settings'),
('en','nav','logout','Sign Out'),
('en','pos','btn_cobrar','Charge'),
('en','pos','btn_create_order','Create Order'),
('en','pos','lbl_total','Total'),
('en','pos','lbl_subtotal','Subtotal'),
('en','pos','lbl_discount','Discount'),
('en','pos','lbl_delivery_fee','Delivery Fee'),
('en','pos','lbl_cart','Cart'),
('en','pos','lbl_empty_cart','Empty cart'),
('en','pos','lbl_add','Add'),
('en','pos','lbl_remove','Remove'),
('en','pos','lbl_qty','Qty'),
('en','license','title_expired','License Expired'),
('en','license','title_no_license','No License'),
('en','license','title_revoked','License Revoked'),
('en','license','title_suspended','License Suspended'),
('en','license','title_no_connection','No Server Connection'),
('en','license','msg_expired','Your license has expired. Contact the provider to renew.'),
('en','license','msg_no_license','No active license on this device. Enter an activation key.'),
('en','license','msg_revoked','This license has been revoked by the administrator.'),
('en','license','msg_suspended','Your license is suspended. Contact the provider.'),
('en','license','msg_no_connection','Could not verify the license. Connect to the internet to continue using the system after 24 hours without verification.'),
('en','license','lbl_activation_key','Activation Key'),
('en','license','lbl_placeholder_key','XXXX-XXXX-XXXX-XXXX'),
('en','license','btn_activate','Activate License'),
('en','license','btn_buy','Buy License'),
('en','license','btn_remove','Remove License'),
('en','license','msg_activating','Activating...'),
('en','license','msg_activated','License activated successfully!'),
('en','license','msg_activation_failed','Failed to activate license.'),
('en','license','msg_enter_key','Enter the activation key.'),
('en','license','lbl_plan','Plan'),
('en','license','lbl_expires','Expires'),
('en','license','lbl_business','Business'),
('en','license','lbl_never','Never'),
('en','license','lbl_valid','Valid'),
('en','general','lbl_search','Search'),
('en','general','lbl_actions','Actions'),
('en','general','lbl_edit','Edit'),
('en','general','lbl_delete','Delete'),
('en','general','lbl_save','Save'),
('en','general','lbl_cancel','Cancel'),
('en','general','lbl_close','Close'),
('en','general','lbl_confirm','Confirm'),
('en','general','lbl_yes','Yes'),
('en','general','lbl_no','No'),
('en','general','lbl_all','All'),
('en','general','lbl_active','Active'),
('en','general','lbl_inactive','Inactive'),
('en','general','msg_saved','Saved successfully'),
('en','general','msg_deleted','Deleted successfully'),
('en','general','msg_error','An error occurred'),
('en','general','msg_no_results','No results'),
('en','general','msg_confirm_delete','Are you sure you item?');

INSERT INTO TRADUCCIONES (codigo_idioma, grupo, clave, valor) VALUES
('pt','auth','btn_login','Entrar'),
('pt','auth','lbl_user','Usuario'),
('pt','auth','lbl_pass','Senha'),
('pt','auth','lbl_placeholder_user','Seu usuario'),
('pt','auth','lbl_placeholder_pass','••••••••'),
('pt','auth','msg_verifying','Verificando...'),
('pt','auth','msg_welcome','Bem-vindo,'),
('pt','auth','msg_invalid','Usuario ou senha incorretos.'),
('pt','auth','msg_complete_fields','Preencha os campos'),
('pt','nav','dashboard','Painel'),
('pt','nav','panel','Painel Garcom'),
('pt','nav','domicilios','Entregas'),
('pt','nav','logout','Sair'),
('pt','license','title_expired','Licenca Expirada'),
('pt','license','title_no_license','Sem Licenca'),
('pt','license','btn_activate','Ativar Licenca'),
('pt','license','btn_buy','Comprar Licenca');

-- Licencia DEMO local + activacion (clave fija para desarrollo local)
INSERT INTO LICENCIAS
    (idLicencia, clave_activacion, negocio_nombre, negocio_nit, contacto_email, contacto_telefono,
     plan, fecha_activacion, fecha_expiracion, estado, max_usuarios, max_mesas, modulos_habilitados)
VALUES
    ('LIC-DEMO-001','DEMO-LOCAL-0001','Freakers','1214722370','searpix@gmail.com','3235636580',
     'ENTERPRISE', NOW(), DATE_ADD(NOW(), INTERVAL 365 DAY), 'ACTIVA', 10, 50,
     '["pos","domicilios","facturacion","whatsapp","dashboard","configuracion"]');

INSERT INTO LICENCIA_ACTIVA (idLicencia, hardware_id, activated_at, last_check) VALUES
    ('LIC-DEMO-001','DEV-MACHINE', NOW(), NOW());

UPDATE CONFIGURACION SET valor = 'DEMO-LOCAL-0001' WHERE parametro = 'LICENCIA_CLAVE';

-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;
-- FIN. Bases 'freakers_licenses' y 'freakers_pos' creadas correctamente.
-- ============================================================
