<?php
/* ============================================
   FREAKERS POS v6 - Authentication API
   Modified: License check before login
   ============================================ */

require_once 'config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = trim($input['action'] ?? '');

        // Logout action - create notification then return success
        if ($action === 'logout') {
            $sesion = getSesion();
            if ($sesion) {
                $pdo = getConnection();
                $chk = $pdo->prepare('SELECT 1 FROM USUARIOS WHERE idUsuario = ?');
                $chk->execute([$sesion['idUsuario']]);
                $uid = $chk->fetch() ? $sesion['idUsuario'] : null;
                if (!empty($sesion['jti'])) {
                    $pdo->prepare('UPDATE POS_SESIONES SET revocado=NOW() WHERE jti=?')->execute([$sesion['jti']]);
                }
                registrarMovimiento($pdo, $uid, 'CIERRE_SESION', null, 'El usuario ' . $sesion['usuario'] . ' cerro sesion');
                $idNot = generarId('NOT');
                $stmtN = $pdo->prepare('INSERT INTO NOTIFICACIONES (idNotificacion, tipo, titulo, mensaje, idReferencia) VALUES (?, ?, ?, ?, ?)');
                $stmtN->execute([$idNot, 'SESION', 'Cierre de sesion', $sesion['usuario'] . ' (' . $sesion['rol'] . ') cerro sesion', $sesion['idUsuario'] ?? 'unknown']);
            }
            jsonOutput(['exito' => true]);
        }

        // ---- LOGIN with license check ----
        $usuario = trim($input['usuario'] ?? '');
        $clave = trim($input['clave'] ?? '');

        if (!$usuario || !$clave) {
            jsonOutput(['exito' => false, 'mensaje' => 'Por favor, completa ambos campos.']);
        }

        $pdo = getConnection();
        posRateLimit($pdo, strtolower($usuario));
        
        // ========== LICENSE VERIFICATION (before credentials) ==========
        $licEstado = verificarLicenciaLocal($pdo);
        
        if (!$licEstado['puede_ingresar']) {
            jsonOutput([
                'exito' => false, 
                'mensaje' => $licEstado['mensaje'],
                'codigo_error' => 'LICENCIA_' . $licEstado['estado'],
                'licencia' => [
                    'estado' => $licEstado['estado'],
                    'mensaje' => $licEstado['mensaje']
                ]
            ]);
        }
        // ========== END LICENSE VERIFICATION ==========

        // Verify credentials
        $stmt = $pdo->prepare('SELECT * FROM USUARIOS WHERE usuario = ? AND estado = ?');
        $stmt->execute([$usuario, 'ACTIVO']);
        $row = $stmt->fetch();

        if (!$row) {
            posRateLimitFail($pdo, strtolower($usuario));
            jsonOutput(['exito' => false, 'mensaje' => 'Usuario o contrasena incorrectos.']);
        }

        // Solo se aceptan hashes password_hash(). Las contraseñas legacy en texto
        // plano quedan bloqueadas y deben restablecerse por un administrador.
        if (!is_string($row['clave']) || !password_verify($clave, $row['clave'])) {
            posRateLimitFail($pdo, strtolower($usuario));
            jsonOutput(['exito' => false, 'mensaje' => 'Usuario o contrasena incorrectos.']);
        }
        posRateLimitClear($pdo, strtolower($usuario));
        if (password_needs_rehash($row['clave'], PASSWORD_DEFAULT)) {
            $upd = $pdo->prepare('UPDATE USUARIOS SET clave = ? WHERE idUsuario = ?');
            $upd->execute([password_hash($clave, PASSWORD_DEFAULT), $row['idUsuario']]);
        }

        // ===== Check license limits (max users on shift) =====
        $licInfo = $licEstado['info'];
        if ($licInfo && isset($licInfo['max_usuarios'])) {
            $maxUsuarios = intval($licInfo['max_usuarios']);
            // Count currently active sessions (users logged in within last 24 hours)
            $activeSessions = $pdo->query('
                SELECT COUNT(DISTINCT idUsuario) as cnt 
                FROM MOVIMIENTOS 
                WHERE accion = "INICIO_SESION" 
                AND fecha > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ')->fetchColumn();
            
            if ($activeSessions >= $maxUsuarios) {
                // Allow if this user is already in the active count (re-login)
                $userActive = $pdo->prepare('
                    SELECT COUNT(*) FROM MOVIMIENTOS 
                    WHERE accion = "INICIO_SESION" 
                    AND idUsuario = ? 
                    AND fecha > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ');
                $userActive->execute([$row['idUsuario']]);
                
                if (!$userActive->fetchColumn()) {
                    jsonOutput([
                        'exito' => false,
                        'mensaje' => "Limite de usuarios alcanzado ($maxUsuarios). Contacta al proveedor para ampliar tu plan.",
                        'codigo_error' => 'LICENCIA_LIMITE_USUARIOS'
                    ]);
                }
            }
        }

        // Create token (simple base64 JSON — upgraded in future with JWT)
        $payload = [
            'idUsuario' => $row['idUsuario'],
            'usuario' => $row['usuario'],
            'nombre' => trim(($row['nombre'] ?? '') . ' ' . ($row['apellido'] ?? '')),
            'rol' => $row['rol'],
            'exp' => time() + 86400,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(16)),
            'lic' => $licEstado['estado'] // Embed license status in token
        ];
        // Create token FIRMADO (base64(JSON).HMAC) — ya no se puede falsificar
        $token = posCrearToken($payload);
        $pdo->exec("CREATE TABLE IF NOT EXISTS POS_SESIONES (
            jti CHAR(32) PRIMARY KEY, idUsuario VARCHAR(50) NOT NULL, creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expira DATETIME NOT NULL, revocado DATETIME NULL, INDEX idx_pos_session_user(idUsuario)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare('INSERT INTO POS_SESIONES (jti,idUsuario,expira) VALUES (?,?,FROM_UNIXTIME(?))')
            ->execute([$payload['jti'],$row['idUsuario'],$payload['exp']]);

        // Audit log
        registrarMovimiento($pdo, $row['idUsuario'], 'INICIO_SESION', null, 'El usuario ' . $row['usuario'] . ' inicio sesion en el sistema');

        // Notification: login
        $idNot = generarId('NOT');
        $stmtN = $pdo->prepare('INSERT INTO NOTIFICACIONES (idNotificacion, tipo, titulo, mensaje, idReferencia) VALUES (?, ?, ?, ?, ?)');
        $stmtN->execute([$idNot, 'SESION', 'Inicio de sesion', $row['usuario'] . ' (' . $row['rol'] . ') inicio sesion', $row['idUsuario']]);

        $sesion = [
            'idUsuario' => $row['idUsuario'],
            'usuario' => $row['usuario'],
            'nombre' => $payload['nombre'],
            'rol' => $row['rol'],
            'token' => $token,
            'licencia_estado' => $licEstado['estado']
        ];

        jsonOutput([
            'exito' => true,
            'sesion' => $sesion,
            'token' => $token,
            'idUsuario' => $row['idUsuario'],
            'usuario' => $row['usuario'],
            'nombre' => $payload['nombre'],
            'rol' => $row['rol'],
            'licencia_estado' => $licEstado['estado']
        ]);
    }

    jsonOutput(['exito' => false, 'mensaje' => 'Metodo no permitido.']);

} catch (Exception $e) {
    error_log('[Comandix][pos-auth] ' . $e->getMessage());
    jsonOutput(['exito' => false, 'mensaje' => 'Error del servidor. Intenta mas tarde.']);
}

/* ============================================
   Local License Verification Function
   Returns array with: puede_ingresar, estado, mensaje, info
   ============================================ */

function posRateLimit($usuario){
    $pdo=getConnection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS POS_LOGIN_RATE (
        scope_hash CHAR(64) PRIMARY KEY,intentos INT NOT NULL DEFAULT 0,
        ventana DATETIME NOT NULL,bloqueado_hasta DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $hash=hash('sha256',$usuario.'|'.($_SERVER['REMOTE_ADDR']??''));
    $st=$pdo->prepare('SELECT intentos,ventana,bloqueado_hasta FROM POS_LOGIN_RATE WHERE scope_hash=?');$st->execute([$hash]);$r=$st->fetch();
    if($r && $r['bloqueado_hasta'] && strtotime($r['bloqueado_hasta'])>time()) jsonOutput(['exito'=>false,'mensaje'=>'Demasiados intentos. Intenta nuevamente en unos minutos.'],429);
    if($r && strtotime($r['ventana'])<time()-900)$pdo->prepare('DELETE FROM POS_LOGIN_RATE WHERE scope_hash=?')->execute([$hash]);
}
function posRateLimitFail($pdo,$usuario){
    $hash=hash('sha256',$usuario.'|'.($_SERVER['REMOTE_ADDR']??''));
    $pdo->prepare("INSERT INTO POS_LOGIN_RATE(scope_hash,intentos,ventana,bloqueado_hasta) VALUES(?,?,NOW(),NULL)
        ON DUPLICATE KEY UPDATE intentos=intentos+1,bloqueado_hasta=IF(intentos+1>=8,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NULL)")
        ->execute([$hash,1]);
}
function posRateLimitClear($pdo,$usuario){
    $hash=hash('sha256',$usuario.'|'.($_SERVER['REMOTE_ADDR']??''));
    $pdo->prepare('DELETE FROM POS_LOGIN_RATE WHERE scope_hash=?')->execute([$hash]);
}
function verificarLicenciaLocal($pdo) {
    // Check if license tables exist first (graceful for first setup)
    try {
        $stmt = $pdo->query('SELECT 1 FROM LICENCIAS LIMIT 1');
    } catch (Exception $e) {
        // Tables don't exist yet — allow login to reach setup
        return [
            'puede_ingresar' => true,
            'estado' => 'SIN_TABLAS',
            'mensaje' => 'Tablas de licencia no encontradas. Ejecuta setup.php',
            'info' => null
        ];
    }
    
    // Get active license with explicit column aliases to avoid JOIN conflicts
    $stmt = $pdo->query('SELECT la.idLicencia, la.hardware_id, la.activated_at, la.last_check, la.check_interval,
        l.clave_activacion, l.negocio_nombre, l.negocio_nit, l.plan, l.fecha_activacion as lic_fecha_activacion,
        l.fecha_expiracion, l.estado, l.max_usuarios, l.max_mesas, l.modulos_habilitados
        FROM LICENCIA_ACTIVA la 
        LEFT JOIN LICENCIAS l ON la.idLicencia = l.idLicencia 
        LIMIT 1');
    $active = $stmt->fetch();
    
    if (!$active) {
        return [
            'puede_ingresar' => false,
            'estado' => 'SIN_LICENCIA',
            'mensaje' => 'No hay licencia activa en este equipo.',
            'info' => null
        ];
    }
    
    $now = time();
    
    // Check if license is expired
    $expDate = $active['fecha_expiracion'] ?? null;
    if ($expDate && strtotime($expDate) < $now) {
        return [
            'puede_ingresar' => false,
            'estado' => 'EXPIRADA',
            'mensaje' => 'Tu licencia expiro el ' . date('d/m/Y', strtotime($expDate)) . '.',
            'info' => $active
        ];
    }
    
    // Check if license is revoked or suspended locally
    $estadoLocal = $active['estado'] ?? 'ACTIVA';
    if ($estadoLocal === 'REVOCADA') {
        return [
            'puede_ingresar' => false,
            'estado' => 'REVOCADA',
            'mensaje' => 'Esta licencia ha sido revocada por el administrador.',
            'info' => $active
        ];
    }
    if ($estadoLocal === 'SUSPENDIDA') {
        return [
            'puede_ingresar' => false,
            'estado' => 'SUSPENDIDA',
            'mensaje' => 'Tu licencia esta suspendida. Contacta al proveedor.',
            'info' => $active
        ];
    }
    
    // Check if we need remote verification
    $lastCheck = strtotime($active['last_check'] ?? '2000-01-01');
    $interval = intval($active['check_interval'] ?? 3600);
    $needsRemote = ($now - $lastCheck) > $interval;
    
    if ($needsRemote) {
        // Try remote verification
        $serverUrl = '';
        try {
            $cfgStmt = $pdo->prepare('SELECT valor FROM CONFIGURACION WHERE parametro = "LICENCIA_SERVER"');
            $cfgStmt->execute();
            $cfgRow = $cfgStmt->fetch();
            $serverUrl = $cfgRow ? trim($cfgRow['valor']) : '';
        } catch (Exception $e) {
            $serverUrl = '';
        }
        
        if (!empty($serverUrl)) {
            $url = rtrim($serverUrl, '/') . '/api/verify.php?key=' . urlencode($active['clave_activacion']) . '&id=' . urlencode($active['idLicencia']);
            
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);
                $response = @curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($response && $httpCode === 200) {
                    $data = json_decode($response, true);
                    if ($data && isset($data['estado'])) {
                        // Update last_check
                        $upd = $pdo->prepare('UPDATE LICENCIA_ACTIVA SET last_check = NOW() WHERE idLicencia = ?');
                        $upd->execute([$active['idLicencia']]);
                        
                        // Sync remote status
                        $upd2 = $pdo->prepare('UPDATE LICENCIAS SET estado = ? WHERE idLicencia = ?');
                        $upd2->execute([$data['estado'], $active['idLicencia']]);
                        
                        if ($data['estado'] !== 'ACTIVA') {
                            return [
                                'puede_ingresar' => false,
                                'estado' => $data['estado'],
                                'mensaje' => $data['mensaje'] ?? 'Licencia no activa segun el servidor.',
                                'info' => $active
                            ];
                        }
                    }
                    // Remote check passed — update last_check
                    $upd = $pdo->prepare('UPDATE LICENCIA_ACTIVA SET last_check = NOW() WHERE idLicencia = ?');
                    $upd->execute([$active['idLicencia']]);
                } else {
                    // Remote check failed — check offline grace period (24 hours)
                    $hoursSinceLastCheck = ($now - $lastCheck) / 3600;
                    if ($hoursSinceLastCheck > 24) {
                        return [
                            'puede_ingresar' => false,
                            'estado' => 'SIN_CONEXION',
                            'mensaje' => 'No se pudo verificar la licencia. Conecta a internet para continuar.',
                            'info' => $active
                        ];
                    }
                }
            }
        }
    }
    
    // All checks passed
    return [
        'puede_ingresar' => true,
        'estado' => 'ACTIVA',
        'mensaje' => 'Licencia activa y verificada.',
        'info' => $active
    ];
}
