<?php
/* ============================================================
   Comandix - Diagnostico y ejecucion directa del aprovisionamiento
   de una instancia POS (base de datos por licencia).

   USO (solo desde la propia maquina / localhost):
     http://localhost/api/provision_diag.php?idLicencia=LIC-XXXX
     http://localhost/api/provision_diag.php?idLicencia=LIC-XXXX&run=1

   - Sin run=1: solo comprueba requisitos (no crea nada).
   - Con run=1: ejecuta el aprovisionamiento real (crea BD + usuario +
     copia del POS) y registra la instancia en la tabla TENANTS.

   Imprime cada paso en texto plano para ver EXACTAMENTE donde falla.
   Restringido a peticiones locales (loopback) porque puede crear bases
   de datos y usuarios MySQL.
   ============================================================ */

header('Content-Type: text/plain; charset=utf-8');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$esLocal = in_array($remote, ['127.0.0.1', '::1', ''], true);
if (!$esLocal) {
    http_response_code(403);
    echo "Acceso denegado. Este diagnostico solo puede ejecutarse desde el propio servidor (localhost).\n";
    exit;
}

$idLic = isset($_GET['idLicencia']) ? trim($_GET['idLicencia']) : '';
$run   = isset($_GET['run']) && $_GET['run'] === '1';

function paso($ok, $titulo, $detalle = '') {
    echo ($ok ? '[ OK ] ' : '[FALLA] ') . $titulo . "\n";
    if ($detalle !== '') echo '        ' . str_replace("\n", "\n        ", $detalle) . "\n";
}

echo "==================================================\n";
echo " Diagnostico de instancias POS - Comandix\n";
echo "==================================================\n\n";

if ($idLic === '') {
    echo "Falta el parametro idLicencia.\n";
    echo "Ejemplo: provision_diag.php?idLicencia=LIC-XXXX  (solo comprueba)\n";
    echo "         provision_diag.php?idLicencia=LIC-XXXX&run=1  (crea la instancia)\n";
    exit;
}

try {
    require_once __DIR__ . '/config.php';
    paso(true, 'config.php cargado.');
} catch (Throwable $e) {
    paso(false, 'No se pudo cargar config.php', $e->getMessage());
    exit;
}

try {
    require_once __DIR__ . '/provisioner.php';
    paso(true, 'provisioner.php cargado.');
} catch (Throwable $e) {
    paso(false, 'No se pudo cargar provisioner.php', $e->getMessage());
    exit;
}

// config.php fuerza Content-Type: application/json; lo devolvemos a texto plano
// para que este diagnostico se lea comodamente en el navegador.
if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');

// 1) Constantes de configuracion (sin exponer contrasenas).
echo "\n-- Configuracion multi-tenant --\n";
echo '  Host MySQL tenant : ' . (defined('LS_TENANT_DB_HOST') ? LS_TENANT_DB_HOST : '(no def)') . "\n";
echo '  Usuario admin MySQL: ' . (defined('LS_TENANT_DB_ADMIN_USER') ? LS_TENANT_DB_ADMIN_USER : '(no def)') . "\n";
echo '  BD plantilla       : ' . (defined('LS_TENANT_TEMPLATE_DB') ? LS_TENANT_TEMPLATE_DB : '(no def)') . "\n";
echo '  Prefijo BD tenant  : ' . (defined('LS_TENANT_DB_PREFIX') ? LS_TENANT_DB_PREFIX : '(no def)') . "\n";
echo '  Carpeta instancias : ' . (defined('LS_TENANT_STORAGE') ? LS_TENANT_STORAGE : '(no def)') . "\n";
echo '  URL base instancias: ' . (defined('LS_TENANT_BASE_URL') ? LS_TENANT_BASE_URL : '(no def)') . "\n\n";

// 2) Conexion como administrador MySQL.
$adminPdo = null;
try {
    $adminPdo = tenantAdminPdo();
    paso(true, 'Conexion a MySQL como administrador.');
} catch (Throwable $e) {
    paso(false, 'No se pudo conectar a MySQL como administrador', $e->getMessage());
    echo "\n  -> Revisa LS_TENANT_DB_ADMIN_USER / LS_TENANT_DB_ADMIN_PASS / LS_TENANT_DB_HOST en el archivo .env raiz.\n";
    exit;
}

// 3) Privilegios del usuario administrador (CREATE, CREATE USER).
try {
    $grants = $adminPdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    $txt = implode("\n", $grants);
    $tieneCreate = (stripos($txt, 'ALL PRIVILEGES') !== false) || (stripos($txt, 'CREATE') !== false);
    $tieneCreateUser = (stripos($txt, 'ALL PRIVILEGES') !== false) || (stripos($txt, 'CREATE USER') !== false) || (stripos($txt, 'GRANT OPTION') !== false);
    paso($tieneCreate, 'Privilegio para crear bases de datos.', $tieneCreate ? '' : 'El usuario admin no parece tener CREATE.');
    paso($tieneCreateUser, 'Privilegio para crear usuarios MySQL.', $tieneCreateUser ? '' : 'El usuario admin no parece tener CREATE USER / GRANT OPTION.');
} catch (Throwable $e) {
    paso(false, 'No se pudieron leer los privilegios (SHOW GRANTS)', $e->getMessage());
}

// 4) Existe la base de datos plantilla y tiene tablas.
try {
    $tpl = LS_TENANT_TEMPLATE_DB;
    $st = $adminPdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $st->execute([$tpl]);
    if ($st->fetchColumn()) {
        $ts = $adminPdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE'");
        $ts->execute([$tpl]);
        $tablas = $ts->fetchAll(PDO::FETCH_COLUMN);
        paso(true, "BD plantilla '$tpl' existe.", 'Tablas (' . count($tablas) . '): ' . implode(', ', $tablas));
        foreach (['USUARIOS', 'LICENCIAS', 'LICENCIA_ACTIVA'] as $req) {
            $ok = in_array($req, array_map('strtoupper', $tablas), true);
            paso($ok, "Tabla requerida '$req' presente en la plantilla.", $ok ? '' : "La plantilla no tiene la tabla $req; el aprovisionamiento fallara al inicializar la instancia.");
        }
    } else {
        paso(false, "BD plantilla '$tpl' NO existe.", "Crea/importa esa base en MySQL o ajusta LS_TENANT_TEMPLATE_DB en el .env raiz.");
    }
} catch (Throwable $e) {
    paso(false, 'Error comprobando la BD plantilla', $e->getMessage());
}

// 5) Carpeta de instancias escribible.
try {
    $dir = rtrim(LS_TENANT_STORAGE, '/\\');
    if (!is_dir($dir)) {
        $creada = @mkdir($dir, 0750, true);
        paso($creada, "Carpeta de instancias creada: $dir", $creada ? '' : 'No se pudo crear la carpeta. Revisa la ruta y permisos.');
    } else {
        paso(true, "Carpeta de instancias existe: $dir");
    }
    if (is_dir($dir)) {
        $probe = $dir . DIRECTORY_SEPARATOR . '.diag_write_test';
        $w = @file_put_contents($probe, 'ok');
        paso($w !== false, 'Escritura en la carpeta de instancias.', $w !== false ? '' : 'No hay permiso de escritura en la carpeta de instancias.');
        if ($w !== false) @unlink($probe);
    }
} catch (Throwable $e) {
    paso(false, 'Error comprobando la carpeta de instancias', $e->getMessage());
}

// 6) Existe la licencia en la BD central (por idLicencia o por clave de activacion).
try {
    $pdo = lsGetConnection();
    $st = $pdo->prepare('SELECT idLicencia, clave_activacion FROM LICENCIAS WHERE idLicencia=? OR clave_activacion=? LIMIT 1');
    $st->execute([$idLic, $idLic]);
    $lic = $st->fetch();
    if ($lic) {
        // Si se busco por clave, usar el idLicencia real de aqui en adelante.
        if (!empty($lic['idLicencia']) && $lic['idLicencia'] !== $idLic) {
            echo "  (Se recibio la clave de activacion; idLicencia real: " . $lic['idLicencia'] . ")\n";
            $idLic = $lic['idLicencia'];
        }
        paso(true, "Licencia '$idLic' existe en la BD central.");
    } else {
        paso(false, "No se encontro la licencia '$idLic'.", 'Verifica el idLicencia (LIC-...) o la clave de activacion exacta.');
    }
} catch (Throwable $e) {
    paso(false, 'Error consultando la licencia', $e->getMessage());
    $lic = null;
}

echo "\n";
if (!$run) {
    echo "Comprobacion finalizada (modo solo lectura).\n";
    echo "Si todo lo anterior esta [ OK ], vuelve a abrir esta URL agregando &run=1 para crear la instancia:\n";
    echo "  provision_diag.php?idLicencia=" . rawurlencode($idLic) . "&run=1\n";
    exit;
}

if (empty($lic)) {
    echo "No se ejecuta el aprovisionamiento porque la licencia no existe.\n";
    exit;
}

// 7) Ejecutar el aprovisionamiento real.
echo "-- Ejecutando aprovisionamiento (run=1) --\n";
try {
    $prov = provisionTenant($idLic, $lic['clave_activacion'] ?? $idLic);
    if (!empty($prov['exito'])) {
        paso(true, 'Instancia POS aprovisionada.');
        echo '  Base de datos : ' . ($prov['dbName'] ?? '') . "\n";
        echo '  Usuario MySQL : ' . ($prov['dbUser'] ?? '') . "\n";
        echo '  URL instancia : ' . ($prov['url'] ?? '') . "\n";
        echo '  Admin usuario : ' . ($prov['tenantAdminUser'] ?? '') . "\n";
        echo '  Admin clave   : ' . ($prov['tenantAdminPassword'] ?? '') . "   (guardala, se muestra una sola vez)\n";
        try {
            registrarTenantEnLicencia($pdo, $idLic, $prov);
            paso(true, 'Instancia registrada en la tabla TENANTS y enlazada a la licencia.');
        } catch (Throwable $e) {
            paso(false, 'La instancia se creo, pero fallo el registro en TENANTS', $e->getMessage());
        }
        echo "\nListo. Abre la URL de la instancia en el navegador.\n";
    } else {
        paso(false, 'El aprovisionamiento devolvio error', $prov['mensaje'] ?? '(sin mensaje)');
    }
} catch (Throwable $e) {
    paso(false, 'Excepcion durante el aprovisionamiento', $e->getMessage());
}
