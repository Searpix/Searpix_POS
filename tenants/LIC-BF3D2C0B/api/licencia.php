<?php
/* ============================================
   FREAKERS POS v6 - License API
   Actions:
     GET  verificar — Check local + remote license status with offline grace
     POST activar  — Activate a license key locally and remotely
     POST remover   — Remove current license (admin only)
     POST check    — Force remote verification
   ============================================ */

require_once 'config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $pdo = getConnection();

    // GET: verificar
    if ($method === 'GET') {
        $action = trim($_GET['action'] ?? '');
        
        if ($action === 'verificar') {
            $status = verificarLicenciaCompleta($pdo);
            jsonOutput($status);
        }
        
        jsonOutput(['exito' => false, 'mensaje' => 'Accion GET no valida.']);
    }

    // POST: activar / remover / check
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = trim($input['action'] ?? '');

        switch ($action) {
            case 'activar':
                $clave = trim($input['clave'] ?? '');
                if (empty($clave)) {
                    jsonOutput(['exito' => false, 'mensaje' => 'Ingresa la clave de activacion.']);
                }
                
                // Check if license already active
                $stmt = $pdo->query('SELECT la.idLicencia FROM LICENCIA_ACTIVA la LIMIT 1');
                if ($stmt->fetch()) {
                    jsonOutput(['exito' => false, 'mensaje' => 'Ya existe una licencia activa. Remuevela primero.']);
                }

                // Look up key in local DB first
                $stmt = $pdo->prepare('SELECT * FROM LICENCIAS WHERE clave_activacion = ? AND estado IN ("ACTIVA","SUSPENDIDA") LIMIT 1');
                $stmt->execute([$clave]);
                $lic = $stmt->fetch();

                if (!$lic) {
                    // Try remote activation
                    $remoteResult = activarLicenciaRemota($pdo, $clave);
                    if (!$remoteResult['exito']) {
                        jsonOutput($remoteResult);
                    }
                    // Re-fetch the newly created/updated license
                    $stmt->execute([$clave]);
                    $lic = $stmt->fetch();
                    if (!$lic) {
                        jsonOutput(['exito' => false, 'mensaje' => 'Error: licencia no encontrada tras activacion remota.']);
                    }
                }

                // Activate locally
                $hardwareId = generarHardwareId();
                $stmt = $pdo->prepare('INSERT INTO LICENCIA_ACTIVA (idLicencia, hardware_id, activated_at, last_check, check_interval) VALUES (?, ?, NOW(), NOW(), 3600)');
                $stmt->execute([$lic['idLicencia'], $hardwareId]);

                // Save key in config
                setConfig($pdo, 'LICENCIA_CLAVE', $clave);

                // Create notification
                crearNotificacion($pdo, 'LICENCIA', 'Licencia Activada', 'Se activo la licencia ' . $lic['plan'] . ' para ' . $lic['negocio_nombre'], $lic['idLicencia']);

                // Audit log
                registrarMovimiento($pdo, null, 'LICENCIA_ACTIVADA', $lic['idLicencia'], 'Licencia ' . $lic['plan'] . ' activada para ' . $lic['negocio_nombre']);

                jsonOutput([
                    'exito' => true,
                    'mensaje' => 'Licencia activada exitosamente!',
                    'licencia' => [
                        'id' => $lic['idLicencia'],
                        'plan' => $lic['plan'],
                        'negocio_nombre' => $lic['negocio_nombre'],
                        'estado' => 'ACTIVA',
                        'fecha_activacion' => $lic['fecha_activacion'],
                        'fecha_expiracion' => $lic['fecha_expiracion'],
                        'max_usuarios' => intval($lic['max_usuarios']),
                        'max_mesas' => intval($lic['max_mesas']),
                        'modulos_habilitados' => json_decode($lic['modulos_habilitados'] ?? '[]', true)
                    ]
                ]);
                break;

            case 'remover':
                $sesion = requireAdmin();
                $adminPass = trim($input['adminPassword'] ?? '');
                
                // Verify admin password
                $stmt = $pdo->prepare('SELECT clave FROM USUARIOS WHERE idUsuario = ? AND estado = "ACTIVO"');
                $stmt->execute([$sesion['idUsuario']]);
                $admin = $stmt->fetch();
                
                if (!$admin || !password_verify($adminPass, $admin['clave'])) {
                    jsonOutput(['exito' => false, 'mensaje' => 'Contrasena de administrador incorrecta.']);
                }

                // Get active license info for audit
                $stmt = $pdo->query('SELECT la.idLicencia, l.clave_activacion FROM LICENCIA_ACTIVA la JOIN LICENCIAS l ON la.idLicencia = l.idLicencia LIMIT 1');
                $activeLic = $stmt->fetch();

                // Remove from LICENCIA_ACTIVA
                $pdo->exec('DELETE FROM LICENCIA_ACTIVA');

                // Clear from config
                setConfig($pdo, 'LICENCIA_CLAVE', '');

                // Notification + audit
                crearNotificacion($pdo, 'LICENCIA', 'Licencia Removida', 'Se removio la licencia activa del equipo', $activeLic['idLicencia'] ?? null);
                registrarMovimiento($pdo, $sesion['idUsuario'], 'LICENCIA_REMOVIDA', $activeLic['idLicencia'] ?? null, 'Licencia removida: ' . ($activeLic['clave_activacion'] ?? 'N/A'));

                jsonOutput(['exito' => true, 'mensaje' => 'Licencia removida exitosamente.']);
                break;

            case 'check':
                $sesion = requireAuth();
                $result = verificarLicenciaCompleta($pdo, true); // force remote
                jsonOutput($result);
                break;

            default:
                jsonOutput(['exito' => false, 'mensaje' => 'Accion no valida.']);
        }
    }

    jsonOutput(['exito' => false, 'mensaje' => 'Metodo no permitido.']);

} catch (Exception $e) {
    error_log('[Comandix][pos-licencia] ' . $e->getMessage());
    jsonOutput(['exito' => false, 'mensaje' => 'Error del servidor.']);
}

/* ============================================
   License Helper Functions
   ============================================ */

/**
 * Full license verification (local + remote with grace period)
 * @param PDO $pdo
 * @param bool $forceRemote Force a remote check regardless of interval
 * @return array Status array
 */
function verificarLicenciaCompleta($pdo, $forceRemote = false) {
    // Check tables exist
    try {
        $pdo->query('SELECT 1 FROM LICENCIAS LIMIT 1');
    } catch (Exception $e) {
        return [
            'exito' => false,
            'estado' => 'SIN_TABLAS',
            'mensaje' => 'Tablas de licencia no encontradas. Ejecuta setup.php primero.',
            'puede_ingresar' => false
        ];
    }

    // Get active license with details
    $stmt = $pdo->query('SELECT la.*, l.clave_activacion, l.negocio_nombre, l.negocio_nit, 
        l.plan, l.fecha_activacion as lic_fecha_activacion, l.fecha_expiracion, l.estado, 
        l.max_usuarios, l.max_mesas, l.modulos_habilitados, l.contacto_email, l.contacto_telefono
        FROM LICENCIA_ACTIVA la 
        LEFT JOIN LICENCIAS l ON la.idLicencia = l.idLicencia 
        LIMIT 1');
    $active = $stmt->fetch();

    if (!$active) {
        return [
            'exito' => false,
            'estado' => 'SIN_LICENCIA',
            'mensaje' => 'No hay licencia activa en este equipo.',
            'puede_ingresar' => false,
            'licencia' => null
        ];
    }

    $now = time();

    // Check local expiration
    $expDate = $active['fecha_expiracion'] ?? null;
    if ($expDate && strtotime($expDate) < $now) {
        return [
            'exito' => false,
            'estado' => 'EXPIRADA',
            'mensaje' => 'Tu licencia expiro el ' . date('d/m/Y', strtotime($expDate)) . '.',
            'puede_ingresar' => false,
            'licencia' => formatLicenciaInfo($active)
        ];
    }

    // Check local status
    $estadoLocal = $active['estado'] ?? 'ACTIVA';
    if ($estadoLocal === 'REVOCADA') {
        return [
            'exito' => false,
            'estado' => 'REVOCADA',
            'mensaje' => 'Esta licencia ha sido revocada por el administrador.',
            'puede_ingresar' => false,
            'licencia' => formatLicenciaInfo($active)
        ];
    }
    if ($estadoLocal === 'SUSPENDIDA') {
        return [
            'exito' => false,
            'estado' => 'SUSPENDIDA',
            'mensaje' => 'Tu licencia esta suspendida. Contacta al proveedor.',
            'puede_ingresar' => false,
            'licencia' => formatLicenciaInfo($active)
        ];
    }

    // Remote verification needed?
    $lastCheck = strtotime($active['last_check'] ?? '2000-01-01');
    $interval = intval($active['check_interval'] ?? 3600);
    $needsRemote = $forceRemote || (($now - $lastCheck) > $interval);

    if ($needsRemote) {
        $serverUrl = getConfig($pdo, 'LICENCIA_SERVER', '');
        
        if (!empty($serverUrl)) {
            $remoteResult = verificarLicenciaRemota($serverUrl, $active['clave_activacion'], $active['idLicencia']);
            
            if ($remoteResult['reached']) {
                // Update last_check timestamp
                $upd = $pdo->prepare('UPDATE LICENCIA_ACTIVA SET last_check = NOW() WHERE idLicencia = ?');
                $upd->execute([$active['idLicencia']]);
                
                if ($remoteResult['estado'] !== 'ACTIVA') {
                    // Sync remote status locally
                    $upd2 = $pdo->prepare('UPDATE LICENCIAS SET estado = ? WHERE idLicencia = ?');
                    $upd2->execute([$remoteResult['estado'], $active['idLicencia']]);
                    
                    return [
                        'exito' => false,
                        'estado' => $remoteResult['estado'],
                        'mensaje' => $remoteResult['mensaje'] ?? 'Licencia no activa segun el servidor.',
                        'puede_ingresar' => false,
                        'licencia' => formatLicenciaInfo($active)
                    ];
                }
                // Remote check passed
            } else {
                // Could not reach remote server — check grace period (24h)
                $hoursSinceLastCheck = ($now - $lastCheck) / 3600;
                if ($hoursSinceLastCheck > 24) {
                    return [
                        'exito' => false,
                        'estado' => 'SIN_CONEXION',
                        'mensaje' => 'No se pudo verificar la licencia. Conecta a internet para continuar usando el sistema.',
                        'puede_ingresar' => false,
                        'licencia' => formatLicenciaInfo($active)
                    ];
                }
                // Within grace period — allow but warn
                return [
                    'exito' => true,
                    'estado' => 'ACTIVA_GRACIA',
                    'mensaje' => 'Sin conexion al servidor de licencias. Periodo de gracia: ' . round(24 - $hoursSinceLastCheck, 1) . ' horas restantes.',
                    'puede_ingresar' => true,
                    'licencia' => formatLicenciaInfo($active)
                ];
            }
        }
    }

    // All checks passed
    return [
        'exito' => true,
        'estado' => 'ACTIVA',
        'mensaje' => 'Licencia activa y verificada.',
        'puede_ingresar' => true,
        'licencia' => formatLicenciaInfo($active)
    ];
}

/**
 * Format license info for API response
 */
function formatLicenciaInfo($row) {
    if (!$row) return null;
    return [
        'id' => $row['idLicencia'],
        'clave' => $row['clave_activacion'] ?? null,
        'plan' => $row['plan'] ?? 'BASICO',
        'negocio_nombre' => $row['negocio_nombre'] ?? '',
        'negocio_nit' => $row['negocio_nit'] ?? '',
        'fecha_activacion' => $row['lic_fecha_activacion'] ?? $row['activated_at'] ?? null,
        'fecha_expiracion' => $row['fecha_expiracion'] ?? null,
        'estado' => $row['estado'] ?? 'ACTIVA',
        'max_usuarios' => intval($row['max_usuarios'] ?? 5),
        'max_mesas' => intval($row['max_mesas'] ?? 20),
        'modulos_habilitados' => json_decode($row['modulos_habilitados'] ?? '[]', true),
        'last_check' => $row['last_check'] ?? null,
        'hardware_id' => $row['hardware_id'] ?? null
    ];
}

/**
 * Generate a hardware fingerprint
 */
function generarHardwareId() {
    $parts = [];
    $parts[] = php_uname('s');  // OS
    $parts[] = php_uname('n');  // Hostname
    $parts[] = php_uname('m');  // Architecture
    
    // Try to get MAC address (works on Linux/Windows)
    $mac = '';
    if (PHP_OS_FAMILY === 'Windows') {
        @exec('getmac', $output, $ret);
        if (!empty($output)) $mac = trim($output[0] ?? '');
    } else {
        @exec('cat /sys/class/net/eth0/address 2>/dev/null', $output, $ret);
        if (!empty($output)) $mac = trim($output[0] ?? '');
    }
    if ($mac) $parts[] = $mac;
    
    return strtoupper(substr(hash('sha256', implode('|', $parts)), 0, 24));
}

/**
 * Verify license with remote server
 * @return array ['reached' => bool, 'estado' => string, 'mensaje' => string]
 */
function verificarLicenciaRemota($serverUrl, $clave, $idLicencia) {
    $url = rtrim($serverUrl, '/') . '/api/verify.php?key=' . urlencode($clave) . '&id=' . urlencode($idLicencia);
    
    if (!function_exists('curl_init')) {
        return ['reached' => false, 'estado' => 'UNKNOWN', 'mensaje' => 'cURL no disponible'];
    }
    
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
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    if ($curlErrno || !$response || $httpCode !== 200) {
        return ['reached' => false, 'estado' => 'UNKNOWN', 'mensaje' => 'Error de conexion al servidor de licencias'];
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['estado'])) {
        return ['reached' => false, 'estado' => 'UNKNOWN', 'mensaje' => 'Respuesta invalida del servidor'];
    }
    
    return [
        'reached' => true,
        'estado' => $data['estado'],
        'mensaje' => $data['mensaje'] ?? '',
        'data' => $data
    ];
}

/**
 * Activate license remotely (register on license server)
 * @return array ['exito' => bool, 'mensaje' => string]
 */
function activarLicenciaRemota($pdo, $clave) {
    $serverUrl = getConfig($pdo, 'LICENCIA_SERVER', '');
    
    if (empty($serverUrl)) {
        return ['exito' => false, 'mensaje' => 'Servidor de licencias no configurado. Activa localmente o configura LICENCIA_SERVER.'];
    }
    
    $hardwareId = generarHardwareId();
    $url = rtrim($serverUrl, '/') . '/api/activate.php';
    
    if (!function_exists('curl_init')) {
        return ['exito' => false, 'mensaje' => 'cURL no disponible para activacion remota.'];
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'key' => $clave,
            'hardware_id' => $hardwareId,
            'hostname' => gethostname(),
            'platform' => PHP_OS_FAMILY
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = @curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$response || $httpCode !== 200) {
        return ['exito' => false, 'mensaje' => 'Error al contactar el servidor de licencias. Intenta mas tarde.'];
    }
    
    $data = json_decode($response, true);
    if (!$data || !$data['exito']) {
        return [
            'exito' => false,
            'mensaje' => $data['mensaje'] ?? 'La clave de activacion no es valida.'
        ];
    }
    
    // Remote activation succeeded — insert/update license locally
    $licData = $data['licencia'] ?? [];
    $idLic = generarId('LIC');
    
    $stmt = $pdo->prepare('INSERT INTO LICENCIAS 
        (idLicencia, clave_activacion, negocio_nombre, negocio_nit, contacto_email, contacto_telefono, 
         plan, fecha_activacion, fecha_expiracion, estado, max_usuarios, max_mesas, modulos_habilitados) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
        estado = VALUES(estado), fecha_expiracion = VALUES(fecha_expiracion), plan = VALUES(plan),
        max_usuarios = VALUES(max_usuarios), max_mesas = VALUES(max_mesas), modulos_habilitados = VALUES(modulos_habilitados)');
    
    $stmt->execute([
        $idLic,
        $clave,
        $licData['negocio_nombre'] ?? '',
        $licData['negocio_nit'] ?? '',
        $licData['contacto_email'] ?? '',
        $licData['contacto_telefono'] ?? '',
        $licData['plan'] ?? 'BASICO',
        $licData['fecha_activacion'] ?? date('Y-m-d H:i:s'),
        $licData['fecha_expiracion'] ?? null,
        'ACTIVA',
        intval($licData['max_usuarios'] ?? 5),
        intval($licData['max_mesas'] ?? 20),
        json_encode($licData['modulos_habilitados'] ?? [])
    ]);
    
    return ['exito' => true, 'mensaje' => 'Licencia activada remotamente.'];
}
