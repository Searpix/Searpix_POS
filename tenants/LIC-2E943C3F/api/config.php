<?php
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);
/* ============================================
   FREAKERS POS v6 - Central Configuration
   All API files require this
   ============================================ */

// Database connection settings
require_once 'db_config.php';

/* ------------------------------------------------------------
   Secreto de firma de tokens del POS.
   Se define en db_config.php (generado por setup). Si no existe,
   se usa un valor por defecto SOLO para que la app no falle, pero
   DEBE cambiarse en produccion (setup.php genera uno aleatorio).
   ------------------------------------------------------------ */
if (!defined('POS_SECRET') || POS_SECRET === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exito'=>false,'mensaje'=>'POS_SECRET no configurado.']);
    exit;
}

/* ------------------------------------------------------------
   Origen permitido para CORS. El POS y su panel viven en el
   MISMO origen que la API, asi que reflejamos el propio host en
   lugar de abrir a "*" (que combinado con credenciales es inseguro).
   ------------------------------------------------------------ */
$__origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Content-Type: application/json; charset=utf-8');
if ($__origin !== '') {
    $__sameOrigin = (strcasecmp(parse_url($__origin, PHP_URL_HOST) ?: '', $_SERVER['HTTP_HOST'] ?? '') === 0);
    if ($__sameOrigin) {
        header('Access-Control-Allow-Origin: ' . $__origin);
        header('Vary: Origin');
    }
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Token');

// Cabeceras de seguridad basicas
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

// Handle preflight
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Colombian timezone
date_default_timezone_set('America/Bogota');

/**
 * Get PDO database connection
 */
function getConnection() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        $pdo->exec("SET time_zone = '-05:00'");
    } catch (PDOException $e) {
        jsonOutput(['exito' => false, 'mensaje' => 'Error de conexion a la base de datos.']);
    }
    return $pdo;
}

/**
 * JSON output with exit
 */
function jsonOutput($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
   Firma HMAC para los tokens del POS.
   ------------------------------------------------------------
   ANTES el token era solo base64(JSON) SIN firma: cualquiera podia
   fabricar uno con rol=ADMIN y entrar como administrador. Ahora el
   token es  base64(JSON).HMAC  y se rechaza si la firma no coincide.
 */
function posFirmarToken($payloadEncoded) {
    return hash_hmac('sha256', $payloadEncoded, POS_SECRET);
}

function posCrearToken(array $payload) {
    $encoded = base64_encode(json_encode($payload));
    return $encoded . '.' . posFirmarToken($encoded);
}

/**
 * Get current session from a SIGNED token (Authorization header,
 * X-Token header. Se valida la firma y la expiracion.
 */
function getSesion() {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = '';

    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
        $token = trim($m[1]);
    } elseif (!empty($_SERVER['HTTP_X_TOKEN'])) {
        $token = trim($_SERVER['HTTP_X_TOKEN']);
    }

    if (empty($token)) return null;

    // El token debe tener formato  payload.firma
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;
    list($encoded, $sig) = $parts;

    // Verificar la firma (comparacion en tiempo constante)
    if (!hash_equals(posFirmarToken($encoded), $sig)) return null;

    $payload = json_decode(base64_decode($encoded), true);
    if (!$payload) return null;
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;
    return $payload;
}

/**
 * Require authentication — returns session or 401
 */
function requireAuth() {
    $sesion = getSesion();
    if (!$sesion) {
        http_response_code(401);
        jsonOutput(['exito' => false, 'mensaje' => 'Sesion expirada. Inicia sesion de nuevo.', 'codigo_error' => 'AUTH_REQUIRED']);
    }
    
    // Check if license is still valid in the token
    $licEstado = $sesion['lic'] ?? 'ACTIVA';
    $badStates = ['EXPIRADA', 'REVOCADA', 'SUSPENDIDA', 'SIN_LICENCIA'];
    if (in_array($licEstado, $badStates)) {
        http_response_code(403);
        jsonOutput([
            'exito' => false,
            'mensaje' => 'Licencia no valida. Contacta al proveedor.',
            'codigo_error' => 'LICENCIA_' . $licEstado
        ]);
    }
    
    return $sesion;
}

/**
 * Require admin role — returns session or 403
 */
function requireAdmin() {
    $sesion = requireAuth();
    if ($sesion['rol'] !== 'ADMIN') {
        http_response_code(403);
        jsonOutput(['exito' => false, 'mensaje' => 'Acceso denegado. Se requiere rol ADMIN.', 'codigo_error' => 'ROLE_REQUIRED']);
    }
    return $sesion;
}

/**
 * Generate unique ID with prefix
 */
function generarId($prefix = 'ID') {
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(5)));
}

/**
 * Register a movement/audit log
 */
function registrarMovimiento($pdo, $idUsuario, $accion, $idReferencia = null, $descripcion = null) {
    try {
        $id = generarId('MOV');
        $stmt = $pdo->prepare('INSERT INTO MOVIMIENTOS (idMovimiento, fecha, idUsuario, accion, idReferencia, descripcion) VALUES (?, NOW(), ?, ?, ?, ?)');
        $stmt->execute([$id, $idUsuario, $accion, $idReferencia, $descripcion]);
        return $id;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get a configuration value
 */
function getConfig($pdo, $parametro, $default = null) {
    try {
        $stmt = $pdo->prepare('SELECT valor FROM CONFIGURACION WHERE parametro = ?');
        $stmt->execute([$parametro]);
        $row = $stmt->fetch();
        return $row ? $row['valor'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Set a configuration value
 */
function setConfig($pdo, $parametro, $valor) {
    try {
        $stmt = $pdo->prepare('INSERT INTO CONFIGURACION (parametro, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?');
        $stmt->execute([$parametro, $valor, $valor]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Create a notification
 */
function crearNotificacion($pdo, $tipo, $titulo, $mensaje, $idReferencia = null) {
    try {
        $id = generarId('NOT');
        $stmt = $pdo->prepare('INSERT INTO NOTIFICACIONES (idNotificacion, tipo, titulo, mensaje, idReferencia) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$id, $tipo, $titulo, $mensaje, $idReferencia]);
        return $id;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Sanitize string for SQL
 */
function sanitize($str) {
    return trim((string)($str ?? ''));
}
function e($str) {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format COP currency
 */
function formatCOP($amount) {
    return '$' . number_format($amount, 0, ',', '.');
}
