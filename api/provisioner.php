<?php
/* ============================================================
   Comandix - Aprovisionador Multi-Tenant seguro
   - BD independiente por tenant
   - usuario MySQL dedicado por tenant
   - copia aislada de /systems
   - .env fuera del código público de la instancia
   ============================================================ */
require_once __DIR__ . '/config.php';

function tenantDbName($idLicencia) {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $idLicencia));
    $slug = trim($slug, '_');
    return LS_TENANT_DB_PREFIX . $slug;
}
function tenantDbUser($idLicencia) {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $idLicencia));
    return substr(LS_TENANT_DB_USER_PREFIX . trim($slug, '_'), 0, 32);
}
function tenantAdminPdo() {
    if (LS_TENANT_DB_ADMIN_USER === '') {
        throw new RuntimeException('Falta LS_TENANT_DB_ADMIN_USER.');
    }

    /*
     * IMPORTANTE:
     * La conexión administrativa se usa para crear bases/usuarios, pero
     * también consulta tablas de la BD central (LICENCIAS, etc.).
     *
     * Antes se conectaba solamente con host, dejando la conexión sin
     * catálogo seleccionado. La primera consulta a LICENCIAS provocaba:
     * SQLSTATE[3D000]: Invalid catalog name: 1046 No database selected
     *
     * Seleccionamos explícitamente la BD central. Esto NO impide ejecutar
     * CREATE DATABASE / CREATE USER / GRANT sobre otras bases.
     */
    if (LS_DB_NAME === '') {
        throw new RuntimeException('Falta LS_DB_NAME: no se puede seleccionar la BD central.');
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', LS_DB_NAME)) {
        throw new RuntimeException('Nombre de BD central invalido.');
    }

    $dsn = 'mysql:host=' . LS_TENANT_DB_HOST .
           ';dbname=' . LS_DB_NAME .
           ';charset=utf8mb4';

    $pdo = new PDO(
        $dsn,
        LS_TENANT_DB_ADMIN_USER,
        LS_TENANT_DB_ADMIN_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    $pdo->exec("SET time_zone = '-05:00'");

    return $pdo;
}
function quoteIdentifier($name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new InvalidArgumentException('Identificador SQL invalido.');
    return '`' . $name . '`';
}
function randomSecret($bytes=32) { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
function sqlPassword($pdo, $password) { return $pdo->quote($password); }

function copyDirectory($source, $dest) {
    if (!is_dir($source)) throw new RuntimeException('No existe la plantilla POS.');
    if (!is_dir($dest) && !mkdir($dest, 0750, true)) throw new RuntimeException('No se pudo crear la instancia.');
    $items = scandir($source);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if ($item === '.env' || $item === '.env.example' || $item === 'db_config.php') continue;
        $src = $source . DIRECTORY_SEPARATOR . $item;
        $dst = $dest . DIRECTORY_SEPARATOR . $item;
        if (is_dir($src)) copyDirectory($src, $dst);
        else {
            if (!copy($src, $dst)) throw new RuntimeException('No se pudo copiar: ' . $item);
            @chmod($dst, 0640);
        }
    }
}
function writeTenantConfig($root, $tenantId, $dbName, $dbUser, $dbPass, $centralKey) {
    $env = implode("\n", [
        'TENANT_ID=' . $tenantId,
        'DB_HOST=' . LS_TENANT_DB_HOST,
        'DB_NAME=' . $dbName,
        'DB_USER=' . $dbUser,
        'DB_PASS=' . $dbPass,
        'CENTRAL_API_URL=' . LS_BASE_URL . '/api',
        'TENANT_API_KEY=' . $centralKey,
        'POS_SECRET=' . randomSecret(48),
        ''
    ]);
    file_put_contents($root . DIRECTORY_SEPARATOR . '.env', $env, LOCK_EX);
    @chmod($root . DIRECTORY_SEPARATOR . '.env', 0600);

    $dbConfig = <<<'PHP'
<?php
function tenantLoadEnv($path) {
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k,$v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v);
        if (strlen($v) >= 2 && (($v[0] === '"' && substr($v,-1)==='"') || ($v[0] === "'" && substr($v,-1)==="'"))) $v=substr($v,1,-1);
        if (preg_match('/^[A-Z0-9_]+$/', $k)) putenv($k.'='.$v);
    }
}
tenantLoadEnv(dirname(__DIR__).'/.env');
function tenantEnv($k,$d=''){ $v=getenv($k); return ($v===false)?$d:$v; }
define('DB_HOST', tenantEnv('DB_HOST','localhost'));
define('DB_NAME', tenantEnv('DB_NAME',''));
define('DB_USER', tenantEnv('DB_USER',''));
define('DB_PASS', tenantEnv('DB_PASS',''));
define('TENANT_ID', tenantEnv('TENANT_ID',''));
define('CENTRAL_API_URL', rtrim(tenantEnv('CENTRAL_API_URL',''),'/'));
define('TENANT_API_KEY', tenantEnv('TENANT_API_KEY',''));
define('POS_SECRET', tenantEnv('POS_SECRET',''));
if (DB_NAME === '' || DB_USER === '' || POS_SECRET === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exito'=>false,'mensaje'=>'Configuracion del tenant incompleta.']);
    exit;
}
PHP;
    file_put_contents($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'db_config.php', $dbConfig, LOCK_EX);
    @chmod($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'db_config.php', 0640);
}

function provisionTenant($idLicencia, $claveActivacion='') {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $idLicencia)) return ['exito'=>false,'mensaje'=>'ID de licencia invalido.'];
    $dbName=tenantDbName($idLicencia); $dbUser=tenantDbUser($idLicencia);
    $template=LS_TENANT_TEMPLATE_DB;
    if (!preg_match('/^[A-Za-z0-9_]+$/',$dbName) || !preg_match('/^[A-Za-z0-9_]+$/',$dbUser) || !preg_match('/^[A-Za-z0-9_]+$/',$template))
        return ['exito'=>false,'mensaje'=>'Nombre de recurso invalido.'];
    $pdo=tenantAdminPdo();
    $createdDb=false;
    $createdFilesystem=false;
    $tenantRoot=null;
    try {
        $licenseStmt=$pdo->prepare('SELECT plan,negocio_nombre,negocio_nit,contacto_email,contacto_telefono,fecha_expiracion,max_usuarios,max_mesas,modulos_habilitados FROM LICENCIAS WHERE idLicencia=? LIMIT 1');
        $licenseStmt->execute([$idLicencia]);
        $license=$licenseStmt->fetch() ?: [];
        $exists=$pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $exists->execute([$dbName]);
        if (!$exists->fetchColumn()) {
            $tmpl=$pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
            $tmpl->execute([$template]);
            if (!$tmpl->fetchColumn()) throw new RuntimeException('La BD plantilla no existe.');
            $pdo->exec('CREATE DATABASE '.quoteIdentifier($dbName).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $createdDb=true;

            $tables=$pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE'");
            $tables->execute([$template]);
            $copyData=['CONFIGURACION','CATEGORIAS','PRODUCTOS','MESAS','APPS_DELIVERY','RESPUESTAS_RAPIDAS','TICKET_CONFIG'];
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach($tables->fetchAll(PDO::FETCH_COLUMN) as $t) {
                if (!preg_match('/^[A-Za-z0-9_]+$/',$t)) continue;
                $pdo->exec('CREATE TABLE '.quoteIdentifier($dbName).'.'.quoteIdentifier($t).' LIKE '.quoteIdentifier($template).'.'.quoteIdentifier($t));
                if (in_array(strtoupper($t),$copyData,true)) {
                    $pdo->exec('INSERT INTO '.quoteIdentifier($dbName).'.'.quoteIdentifier($t).' SELECT * FROM '.quoteIdentifier($template).'.'.quoteIdentifier($t));
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $dbPass=randomSecret(32);
        // CREATE/ALTER USER is idempotent. DB credentials remain server-side and are written only to the tenant .env.
        $mysqlUserHost = LS_TENANT_DB_HOST;
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $mysqlUserHost)) throw new RuntimeException('Host MySQL de tenant invalido.');
        $host = $pdo->quote($mysqlUserHost);
        $pdo->exec('CREATE USER IF NOT EXISTS '.$pdo->quote($dbUser).'@'.$host.' IDENTIFIED BY '.$pdo->quote($dbPass));
        $pdo->exec('ALTER USER '.$pdo->quote($dbUser).'@'.$host.' IDENTIFIED BY '.$pdo->quote($dbPass));
        $pdo->exec('GRANT ALL PRIVILEGES ON '.quoteIdentifier($dbName).'.* TO '.$pdo->quote($dbUser).'@'.$host);
        $pdo->exec('FLUSH PRIVILEGES');

        $tenantRoot=rtrim(LS_TENANT_STORAGE,'/\\').DIRECTORY_SEPARATOR.$idLicencia;
        if (!is_dir($tenantRoot)) {
            copyDirectory(dirname(__DIR__).DIRECTORY_SEPARATOR.'systems',$tenantRoot);
            $createdFilesystem=true;
        }
        $centralKey=randomSecret(48);
        writeTenantConfig($tenantRoot,$idLicencia,$dbName,$dbUser,$dbPass,$centralKey);

        // Inicializar licencia local y un usuario propietario NUEVO. Nunca se copian
        // usuarios/contraseñas de la plantilla entre tenants.
        $tenantPdo = new PDO('mysql:host=' . LS_TENANT_DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
            $dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $tenantPdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try { $tenantPdo->exec('DELETE FROM LICENCIA_ACTIVA'); } catch(Throwable $e) {}
        try { $tenantPdo->exec('DELETE FROM LICENCIAS'); } catch(Throwable $e) {}
        try { $tenantPdo->exec('DELETE FROM USUARIOS'); } catch(Throwable $e) {}
        $tenantPdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $tenantAdminUser = 'ADMIN';
        $tenantAdminPassword = randomSecret(18);
        $stmt=$tenantPdo->prepare('INSERT INTO USUARIOS (idUsuario,usuario,clave,nombre,apellido,rol,estado,fechaCreacion) VALUES (?,?,?,?,?,?,?,NOW())');
        $stmt->execute(['USR-OWNER',$tenantAdminUser,password_hash($tenantAdminPassword,PASSWORD_DEFAULT),'Administrador','Tenant','ADMIN','ACTIVO']);
        $stmt=$tenantPdo->prepare('INSERT INTO LICENCIAS (idLicencia,clave_activacion,negocio_nombre,negocio_nit,contacto_email,contacto_telefono,plan,fecha_activacion,fecha_expiracion,estado,max_usuarios,max_mesas,modulos_habilitados) VALUES (?,?,?,?,?,?,?,NOW(),?, ?,?,?,?)');
        $stmt->execute([
            $idLicencia,$claveActivacion ?: $idLicencia,$license['negocio_nombre'] ?? 'Tenant POS',
            $license['negocio_nit'] ?? null,$license['contacto_email'] ?? null,$license['contacto_telefono'] ?? null,
            $license['plan'] ?? 'BASICO',$license['fecha_expiracion'] ?? null,'ACTIVA',
            (int)($license['max_usuarios'] ?? 5),(int)($license['max_mesas'] ?? 20),
            $license['modulos_habilitados'] ?? json_encode(['pos','dashboard','configuracion'])
        ]);
        $tenantPdo->prepare('INSERT INTO LICENCIA_ACTIVA (idLicencia,hardware_id,activated_at,last_check,check_interval) VALUES (?,?,NOW(),NOW(),3600)')
            ->execute([$idLicencia,'SERVER-TENANT']);

        // Tenant web root config: clean URL /tenant/<id>/... and no direct access to storage.
        writeTenantHtaccess($tenantRoot, $idLicencia);
        file_put_contents($tenantRoot.'/.provisioned', date('c'), LOCK_EX);
        @chmod($tenantRoot.'/.provisioned',0600);
        $url=rtrim(LS_TENANT_BASE_URL,'/').'/'.rawurlencode($idLicencia).'/';
        return ['exito'=>true,'dbName'=>$dbName,'dbUser'=>$dbUser,'url'=>$url,'tenantKey'=>$centralKey,'tenantAdminUser'=>$tenantAdminUser,'tenantAdminPassword'=>$tenantAdminPassword,'mensaje'=>'Instancia POS aprovisionada.'];
    } catch(Throwable $e) {
        error_log('[Comandix][provisioner] '.$e->getMessage());
        // Rollback best-effort: nunca dejar una BD o filesystem parcialmente aprovisionado.
        if ($createdFilesystem && !empty($tenantRoot) && is_dir($tenantRoot)) {
            removeDirectory($tenantRoot);
        }
        if (!empty($createdDb)) {
            try { $pdo->exec('DROP DATABASE IF EXISTS '.quoteIdentifier($dbName)); } catch(Throwable $ignored) {}
            try {
                $mysqlUserHost = LS_TENANT_DB_HOST;
                $pdo->exec('DROP USER IF EXISTS '.$pdo->quote($dbUser).'@'.$pdo->quote($mysqlUserHost));
            } catch(Throwable $ignored) {}
        }
        return ['exito'=>false,'mensaje'=>'No se pudo aprovisionar la instancia POS: '.$e->getMessage()];
    }
}
function removeDirectory($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item==='.'||$item==='..') continue;
        $p=$dir.DIRECTORY_SEPARATOR.$item;
        if (is_dir($p) && !is_link($p)) removeDirectory($p); else @unlink($p);
    }
    @rmdir($dir);
}
function writeTenantHtaccess($tenantRoot,$tenantId) {
    $content=<<<HT
Options -Indexes
AddDefaultCharset UTF-8
<IfModule mod_headers.c>
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
</IfModule>
<FilesMatch "^(?:\.env|.*\.(?:sql|bak|log|ini|conf))$">
Require all denied
</FilesMatch>
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteRule ^(?:config|backend|storage)(/|$) - [F,L,NC]
RewriteCond %{THE_REQUEST} \s/+.*\.html(?:[\s?]|$) [NC]
RewriteRule ^(.+)\.html$ $1 [R=301,L,NE]
RewriteCond %{THE_REQUEST} \s/+.*\.php(?:[\s?]|$) [NC]
RewriteRule ^(.+)\.php$ $1 [R=301,L,NE]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME}.html -f
RewriteRule ^(.+?)/?$ $1.html [END,QSA]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME}.php -f
RewriteRule ^(.+?)/?$ $1.php [END,QSA]
</IfModule>
HT;
    file_put_contents($tenantRoot.'/.htaccess',$content,LOCK_EX);
    @chmod($tenantRoot.'/.htaccess',0640);
}
function registrarTenantEnLicencia($pdo,$idLicencia,$prov) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS TENANTS (
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
        INDEX idx_tenant_license (idLicencia),
        INDEX idx_tenant_status (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $tenantId='TEN-'.$idLicencia;
    $stmt=$pdo->prepare('INSERT INTO TENANTS (idTenant,idLicencia,db_name,db_user,db_host,base_url,storage_path,api_key_hash,estado)
        VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE db_name=VALUES(db_name),db_user=VALUES(db_user),db_host=VALUES(db_host),base_url=VALUES(base_url),storage_path=VALUES(storage_path),api_key_hash=VALUES(api_key_hash),estado="ACTIVO"');
    $stmt->execute([$tenantId,$idLicencia,$prov['dbName'],$prov['dbUser'],LS_TENANT_DB_HOST,$prov['url'],rtrim(LS_TENANT_STORAGE,'/\\').'/'.$idLicencia,hash('sha256',$prov['tenantKey']),'ACTIVO']);
    foreach ([
        'ALTER TABLE LICENCIAS ADD COLUMN idTenant VARCHAR(64) NULL UNIQUE',
        'ALTER TABLE LICENCIAS ADD COLUMN db_tenant VARCHAR(120) NULL',
        'ALTER TABLE LICENCIAS ADD COLUMN url_instancia VARCHAR(255) NULL'
    ] as $sql) {
        try { $pdo->exec($sql); } catch(Throwable $e) {}
    }
    $pdo->prepare('UPDATE LICENCIAS SET idTenant=?,db_tenant=?,url_instancia=? WHERE idLicencia=?')->execute([$tenantId,$prov['dbName'],$prov['url'],$idLicencia]);
    return $tenantId;
}
