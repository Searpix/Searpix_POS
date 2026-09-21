-- ============================================================
-- Comandix / Freakers SaaS - Migracion de hardening y multi-tenant
-- MySQL 8+
-- Ejecutar sobre la BD CENTRAL (freakers_licenses)
-- ============================================================

CREATE TABLE IF NOT EXISTS TENANTS (
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
  INDEX idx_tenant_status (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS SESIONES (
  jti CHAR(32) PRIMARY KEY,
  tipo VARCHAR(20) NOT NULL,
  idUsuario VARCHAR(64) NOT NULL,
  expira DATETIME NOT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revocado DATETIME NULL,
  INDEX idx_sesion_user (tipo,idUsuario),
  INDEX idx_sesion_expira (expira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS LOGIN_RATE_LIMIT (
  scope_hash CHAR(64) PRIMARY KEY,
  intentos INT NOT NULL DEFAULT 0,
  ventana DATETIME NOT NULL,
  bloqueado_hasta DATETIME NULL,
  INDEX idx_bloqueo (bloqueado_hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS TICKETS (
  idTicket VARCHAR(50) PRIMARY KEY,
  idCliente VARCHAR(50) NULL,
  idTenant VARCHAR(64) NULL,
  tenantUserId VARCHAR(64) NULL,
  tenantUserName VARCHAR(200) NULL,
  origen VARCHAR(20) NOT NULL DEFAULT 'PORTAL',
  asunto VARCHAR(200) NOT NULL,
  categoria VARCHAR(50) DEFAULT 'GENERAL',
  prioridad ENUM('BAJA','NORMAL','ALTA','URGENTE') DEFAULT 'NORMAL',
  estado ENUM('ABIERTO','EN_PROCESO','RESUELTO') DEFAULT 'ABIERTO',
  escalado TINYINT(1) DEFAULT 0,
  creado DATETIME DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cliente (idCliente),
  INDEX idx_tenant (idTenant,tenantUserId),
  INDEX idx_estado_actualizado (estado,actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS TICKET_MENSAJES (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  idTicket VARCHAR(50) NOT NULL,
  autorTipo VARCHAR(20) NOT NULL,
  autorId VARCHAR(64) NOT NULL,
  mensaje TEXT NOT NULL,
  creado DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ticket_creado (idTicket,creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibilidad con instalaciones anteriores
ALTER TABLE TICKETS MODIFY idCliente VARCHAR(50) NULL;
ALTER TABLE TICKETS ADD COLUMN idTenant VARCHAR(64) NULL;
ALTER TABLE TICKETS ADD COLUMN tenantUserId VARCHAR(64) NULL;
ALTER TABLE TICKETS ADD COLUMN tenantUserName VARCHAR(200) NULL;
ALTER TABLE TICKETS ADD COLUMN origen VARCHAR(20) NOT NULL DEFAULT 'PORTAL';
ALTER TABLE TICKETS ADD COLUMN escalado TINYINT(1) DEFAULT 0;
ALTER TABLE TICKETS ADD INDEX idx_tenant (idTenant,tenantUserId);
ALTER TABLE TICKETS ADD INDEX idx_estado_actualizado (estado,actualizado);
UPDATE TICKETS SET estado='EN_PROCESO' WHERE estado='RESPONDIDO';
UPDATE TICKETS SET estado='RESUELTO' WHERE estado='CERRADO';
ALTER TABLE TICKETS MODIFY estado ENUM('ABIERTO','EN_PROCESO','RESUELTO') NOT NULL DEFAULT 'ABIERTO';

-- Si la instalación antigua usa autorTipo como ENUM, convertirlo a VARCHAR
ALTER TABLE TICKET_MENSAJES MODIFY autorTipo VARCHAR(20) NOT NULL;

-- ============================================================
-- BD TENANT (cada BD creada por provisioner)
-- ============================================================
-- La estructura completa se clona de systems/api/setup.php.
-- NO se copian USUARIOS ni LICENCIAS desde la plantilla.
-- El provisioner crea:
--   USR-OWNER / ADMIN / contraseña aleatoria
--   licencia local vinculada al idLicencia central
--   LICENCIA_ACTIVA
--   usuario MySQL dedicado con GRANT sobre solo esa BD.
