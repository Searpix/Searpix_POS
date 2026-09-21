<?php
/* ============================================
   LICENSE SERVER — Authentication API
   Both admin and client login/register
   ============================================ */

require_once 'config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $pdo = lsGetConnection();

    if ($method !== 'POST') lsJsonOutput(['exito' => false, 'mensaje' => 'Método no permitido. Usa POST.'], 405);

    list($action, $input) = lsGetAction();

    switch ($action) {
        case 'logout':
            $auth = lsRequireAuth();
            $pdo->prepare('UPDATE SESIONES SET revocado=NOW() WHERE jti=?')->execute([$auth['jti']]);
            lsJsonOutput(['exito'=>true,'mensaje'=>'Sesion cerrada.']);
            break;
        case 'admin_login':
            $usuario = trim($input['usuario'] ?? '');
            $clave = $input['clave'] ?? '';

            if (empty($usuario) || empty($clave))
                lsJsonOutput(['exito' => false, 'mensaje' => 'Completa todos los campos.']);
            lsRateLimit($pdo, 'admin:' . strtolower($usuario));

            $stmt = $pdo->prepare('SELECT * FROM ADMIN_USUARIOS WHERE usuario = ? AND estado = "ACTIVO"');
            $stmt->execute([$usuario]);
            $admin = $stmt->fetch();

            if (!$admin || !password_verify($clave, $admin['clave'])) {
                lsRateLimitFail($pdo, 'admin:' . strtolower($usuario));
                lsJsonOutput(['exito' => false, 'mensaje' => 'Credenciales incorrectas.']);
            }
            lsRateLimitClear($pdo, 'admin:' . strtolower($usuario));

            $pdo->prepare('UPDATE ADMIN_USUARIOS SET ultimoLogin = NOW() WHERE idAdmin = ?')->execute([$admin['idAdmin']]);

            $token = lsCreateToken('admin', $admin['idAdmin'], [
                'usuario' => $admin['usuario'],
                'nombre' => $admin['nombre'],
                'rol' => $admin['rol']
            ]);
            lsRegisterSession($pdo, $token, 'admin', $admin['idAdmin']);

            lsLog($pdo, 'ADMIN_LOGIN', 'Inicio de sesión admin: ' . $admin['usuario'], null, $admin['idAdmin']);

            lsJsonOutput([
                'exito' => true,
                'mensaje' => 'Bienvenido, ' . $admin['nombre'],
                'tipo' => 'admin',
                'token' => $token,
                'usuario' => [
                    'id' => $admin['idAdmin'],
                    'usuario' => $admin['usuario'],
                    'nombre' => $admin['nombre'],
                    'email' => $admin['email'],
                    'rol' => $admin['rol']
                ]
            ]);
            break;

        case 'client_login':
            $email = trim($input['email'] ?? '');
            $clave = $input['clave'] ?? '';

            if (empty($email) || empty($clave))
                lsJsonOutput(['exito' => false, 'mensaje' => 'Completa todos los campos.']);
            lsRateLimit($pdo, 'client:' . strtolower($email));

            $stmt = $pdo->prepare('SELECT * FROM CLIENTES WHERE email = ? AND estado != "SUSPENDIDO"');
            $stmt->execute([$email]);
            $client = $stmt->fetch();

            if (!$client || !password_verify($clave, $client['clave'])) {
                lsRateLimitFail($pdo, 'client:' . strtolower($email));
                lsJsonOutput(['exito' => false, 'mensaje' => 'Credenciales incorrectas o cuenta suspendida.']);
            }
            lsRateLimitClear($pdo, 'client:' . strtolower($email));

            $pdo->prepare('UPDATE CLIENTES SET ultimoLogin = NOW() WHERE idCliente = ?')->execute([$client['idCliente']]);

            $token = lsCreateToken('client', $client['idCliente'], [
                'nombre' => $client['nombre'],
                'email' => $client['email'],
                'empresa' => $client['empresa']
            ]);
            lsRegisterSession($pdo, $token, 'client', $client['idCliente']);

            lsLog($pdo, 'CLIENT_LOGIN', 'Inicio de sesión cliente: ' . $client['email'], null, null, $client['idCliente']);

            lsJsonOutput([
                'exito' => true,
                'mensaje' => 'Bienvenido, ' . $client['nombre'],
                'tipo' => 'client',
                'token' => $token,
                'usuario' => [
                    'id' => $client['idCliente'],
                    'nombre' => $client['nombre'],
                    'apellido' => $client['apellido'],
                    'email' => $client['email'],
                    'empresa' => $client['empresa'],
                    'telefono' => $client['telefono'],
                    'whatsapp' => $client['whatsapp'],
                    'ciudad' => $client['ciudad']
                ]
            ]);
            break;

        case 'client_register':
            $nombre = trim($input['nombre'] ?? '');
            $email = trim($input['email'] ?? '');
            $clave = $input['clave'] ?? '';
            $telefono = trim($input['telefono'] ?? '');
            $empresa = trim($input['restaurante'] ?? $input['empresa'] ?? '');
            $ciudad = trim($input['ciudad'] ?? '');
            $nit = trim($input['nit'] ?? '');
            $whatsapp = trim($input['whatsapp'] ?? $telefono);

            if (empty($nombre) || empty($email) || empty($clave))
                lsJsonOutput(['exito' => false, 'mensaje' => 'Nombre, email y contraseña son obligatorios.']);

            if (strlen($clave) < 6)
                lsJsonOutput(['exito' => false, 'mensaje' => 'La contraseña debe tener al menos 6 caracteres.']);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL))
                lsJsonOutput(['exito' => false, 'mensaje' => 'Email no válido.']);

            // Check existing email
            $stmt = $pdo->prepare('SELECT idCliente FROM CLIENTES WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch())
                lsJsonOutput(['exito' => false, 'mensaje' => 'Ya existe una cuenta con este email.']);

            $idCliente = lsGenerarId('CLI-');
            $hash = password_hash($clave, PASSWORD_DEFAULT);

            $pdo->prepare('INSERT INTO CLIENTES (idCliente, nombre, email, telefono, empresa, nit, ciudad, clave, whatsapp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$idCliente, $nombre, $email, $telefono, $empresa ?: null, $nit ?: null, $ciudad ?: null, $hash, $whatsapp]);

            // Create trial license
            $diasPrueba = intval(lsGetConfig($pdo, 'DIAS_PRUEBA', '7'));
            $idLic = lsGenerarId('LIC-');
            $claveLic = lsGenerarClave();
            $pdo->prepare('INSERT INTO LICENCIAS 
                (idLicencia, clave_activacion, idCliente, idPlan, negocio_nombre, plan, duracion_meses, fecha_activacion, fecha_expiracion, estado, max_usuarios, max_mesas, modulos_habilitados, activaciones, max_activaciones) 
                VALUES (?, ?, ?, "PLAN-BAS", ?, "BASICO", 0, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), "ACTIVA", 3, 15, ?, 0, 1)')
                ->execute([
                    $idLic, $claveLic, $idCliente, $empresa ?: $nombre,
                    $diasPrueba,
                    json_encode(['pos','categorias','productos','ordenes','pagos'])
                ]);

            // Provisionar automáticamente el POS del trial.
            try {
                require_once __DIR__ . '/provisioner.php';
                $prov = provisionTenant($idLic, $claveLic);
                if ($prov['exito']) {
                    registrarTenantEnLicencia($pdo, $idLic, $prov);
                } else {
                    lsLog($pdo, 'TENANT_PROVISION_FAIL', $prov['mensaje'], $idLic, null, $idCliente);
                }
            } catch (Throwable $e) {
                error_log('[Comandix][auth][provision] ' . $e->getMessage());
                lsLog($pdo, 'TENANT_PROVISION_FAIL', 'Excepcion: ' . $e->getMessage(), $idLic, null, $idCliente);
            }
            lsLog($pdo, 'CLIENT_REGISTER', 'Nuevo registro: ' . $email . ' — Licencia trial ' . $diasPrueba . ' días', $idLic, null, $idCliente);

            // Auto-login after register
            $token = lsCreateToken('client', $idCliente, [
                'nombre' => $nombre,
                'email' => $email,
                'empresa' => $empresa
            ]);
            lsRegisterSession($pdo, $token, 'client', $idCliente);

            lsJsonOutput([
                'exito' => true,
                'mensaje' => 'Cuenta creada. Tienes ' . $diasPrueba . ' días de prueba gratuita.',
                'tipo' => 'client',
                'token' => $token,
                'trial_key' => $claveLic,
                'usuario' => [
                    'id' => $idCliente,
                    'nombre' => $nombre,
                    'email' => $email,
                    'empresa' => $empresa,
                    'telefono' => $telefono,
                    'ciudad' => $ciudad
                ]
            ]);
            break;

        case 'me':
            $payload = lsGetAuth();
            if (!$payload) lsJsonOutput(['exito' => false, 'mensaje' => 'No autenticado.'], 401);

            if ($payload['type'] === 'admin') {
                $stmt = $pdo->prepare('SELECT idAdmin, usuario, nombre, email, rol, estado, ultimoLogin FROM ADMIN_USUARIOS WHERE idAdmin = ?');
                $stmt->execute([$payload['id']]);
                $user = $stmt->fetch();
                $tipo = 'admin';
            } else {
                $stmt = $pdo->prepare('SELECT idCliente, nombre, apellido, email, telefono, empresa, nit, whatsapp, ciudad, estado, ultimoLogin FROM CLIENTES WHERE idCliente = ?');
                $stmt->execute([$payload['id']]);
                $user = $stmt->fetch();
                $tipo = 'client';
            }

            lsJsonOutput(['exito' => true, 'tipo' => $tipo, 'usuario' => $user]);
            break;

        default:
            lsJsonOutput(['exito' => false, 'mensaje' => 'Acción no válida: ' . $action]);
    }
} catch (Throwable $e) {
    error_log('[Comandix][auth] ' . $e->getMessage());
    lsJsonOutput(['exito' => false, 'mensaje' => 'Error del servidor. Intenta mas tarde.'], 500);
}

/* ---- Helpers (top-level: deben declararse FUERA del try ---- */
function lsRateLimit($pdo,$scope){
    $pdo->exec("CREATE TABLE IF NOT EXISTS LOGIN_RATE_LIMIT (
        scope_hash CHAR(64) PRIMARY KEY, intentos INT NOT NULL DEFAULT 0,
        ventana DATETIME NOT NULL, bloqueado_hasta DATETIME NULL,
        INDEX idx_bloqueo(bloqueado_hasta)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $hash=hash('sha256',$scope.'|'.($_SERVER['REMOTE_ADDR']??''));
    $st=$pdo->prepare('SELECT intentos,ventana,bloqueado_hasta FROM LOGIN_RATE_LIMIT WHERE scope_hash=?');$st->execute([$hash]);$r=$st->fetch();
    if($r && $r['bloqueado_hasta'] && strtotime($r['bloqueado_hasta'])>time()) lsJsonOutput(['exito'=>false,'mensaje'=>'Demasiados intentos. Intenta nuevamente en unos minutos.'],429);
    if($r && strtotime($r['ventana']) < time()-900) $pdo->prepare('DELETE FROM LOGIN_RATE_LIMIT WHERE scope_hash=?')->execute([$hash]);
}
function lsRateLimitFail($pdo,$scope){
    $hash=hash('sha256',$scope.'|'.($_SERVER['REMOTE_ADDR']??''));$now=time();
    $pdo->prepare("INSERT INTO LOGIN_RATE_LIMIT(scope_hash,intentos,ventana,bloqueado_hasta) VALUES(?,?,NOW(),NULL)
        ON DUPLICATE KEY UPDATE intentos=intentos+1,bloqueado_hasta=IF(intentos+1>=8,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NULL)")
        ->execute([$hash,1]);
}
function lsRateLimitClear($pdo,$scope){
    $hash=hash('sha256',$scope.'|'.($_SERVER['REMOTE_ADDR']??''));$pdo->prepare('DELETE FROM LOGIN_RATE_LIMIT WHERE scope_hash=?')->execute([$hash]);
}
function lsRegisterSession($pdo,$token,$type,$id){
    $parts=explode('.',$token,2);
    if(count($parts)!==2) throw new RuntimeException('Token invalido.');
    $payload=json_decode(base64_decode($parts[0]),true);
    if(!$payload||empty($payload['jti'])) throw new RuntimeException('Token invalido.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS SESIONES (
        jti CHAR(32) PRIMARY KEY,tipo VARCHAR(20) NOT NULL,idUsuario VARCHAR(64) NOT NULL,
        expira DATETIME NOT NULL,creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,revocado DATETIME NULL,
        INDEX idx_sesion_user(tipo,idUsuario),INDEX idx_sesion_expira(expira)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare('INSERT INTO SESIONES (jti,tipo,idUsuario,expira) VALUES (?,?,?,FROM_UNIXTIME(?))')
        ->execute([$payload['jti'],$type,$id,$payload['exp']]);
}
