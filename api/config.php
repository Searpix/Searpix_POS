<?php
// En produccion local: nunca mostrar avisos/errores PHP dentro del JSON,
// para que el frontend siempre reciba una respuesta limpia y valida.
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);
/* ============================================
   LICENSE SERVER — Central Config & Helpers
   ============================================ */

require_once 'db_config.php';

// CORS estricto: solo el origen publico configurado. Para llamadas same-origin
// no es necesario enviar ACAO.
$__origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$__allowed = rtrim(LS_BASE_URL, '/');
if ($__origin !== '' && strcasecmp(rtrim($__origin,'/'), $__allowed) === 0) {
    header('Access-Control-Allow-Origin: ' . $__origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function lsGetConnection() {
    return lsConnection();
}

function lsJsonOutput($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function lsGenerarId($prefix = '') {
    return $prefix . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}

function lsGenerarClave() {
    $seg = function() { return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4)); };
    return $seg() . '-' . $seg() . '-' . $seg() . '-' . $seg();
}

function lsHashToken($data) {
    if (LS_SECRET === '') throw new RuntimeException('LS_SECRET no configurado.');
    return hash_hmac('sha256', $data, LS_SECRET);
}

function lsCreateToken($userType, $userId, $extra = []) {
    $payload = [
        'type' => $userType,
        'id' => $userId,
        'exp' => time() + (LS_TOKEN_HOURS * 3600),
        'iat' => time(),
        'jti' => bin2hex(random_bytes(16)),
        'extra' => $extra
    ];
    $encoded = base64_encode(json_encode($payload));
    $signature = lsHashToken($encoded);
    return $encoded . '.' . $signature;
}

function lsVerifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;
    $encoded = $parts[0];
    $sig = $parts[1];
    if (!hash_equals(lsHashToken($encoded), $sig)) return null;
    $payload = json_decode(base64_decode($encoded), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) return null;
    return $payload;
}

function lsGetAuth() {
    $token = lsGetBearerToken();
    if (empty($token)) return null;
    return lsVerifyToken($token);
}

/**
 * Obtiene el token Bearer buscando en TODAS las fuentes posibles.
 * En XAMPP/Apache el header Authorization con frecuencia NO llega a PHP
 * (queda en REDIRECT_HTTP_AUTHORIZATION o solo en apache_request_headers()).
 * Revisamos todas para evitar el error "No autenticado.".
 */
function lsGetBearerToken() {
    $authHeader = '';

    // 1) Variables de servidor habituales
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    // 2) apache_request_headers() (case-insensitive)
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $authHeader = $v; break; }
        }
    }

    // 3) getallheaders() como respaldo
    if (empty($authHeader) && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $authHeader = $v; break; }
        }
    }

    if (empty($authHeader)) return '';
    // Quitar el prefijo "Bearer " (insensible a mayúsculas)
    return trim(preg_replace('/^\s*Bearer\s+/i', '', $authHeader));
}

function lsRequireAuth($type = null) {
    $payload = lsGetAuth();
    if (!$payload) lsJsonOutput(['exito' => false, 'mensaje' => 'No autenticado.'], 401);
    if ($type && ($payload['type'] ?? '') !== $type) lsJsonOutput(['exito' => false, 'mensaje' => 'Acceso denegado.'], 403);
    $pdo = lsGetConnection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS SESIONES (
        jti CHAR(32) PRIMARY KEY, tipo VARCHAR(20) NOT NULL, idUsuario VARCHAR(64) NOT NULL,
        expira DATETIME NOT NULL, creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, revocado DATETIME NULL,
        INDEX idx_sesion_user(tipo,idUsuario), INDEX idx_sesion_expira(expira)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st=$pdo->prepare('SELECT 1 FROM SESIONES WHERE jti=? AND tipo=? AND idUsuario=? AND revocado IS NULL AND expira>NOW()');
    $st->execute([$payload['jti']??'',$payload['type']??'',$payload['id']??'']);
    if(!$st->fetchColumn()) lsJsonOutput(['exito'=>false,'mensaje'=>'Sesion revocada o expirada.'],401);
    return $payload;
}

function lsGetConfig($pdo, $key, $default = '') {
    $stmt = $pdo->prepare('SELECT valor FROM CONFIGURACION WHERE parametro = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

function lsSetConfig($pdo, $key, $value) {
    $stmt = $pdo->prepare('INSERT INTO CONFIGURACION (parametro, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?');
    $stmt->execute([$key, $value, $value]);
}

function lsLog($pdo, $accion, $descripcion, $idLicencia = null, $idAdmin = null, $idCliente = null) {
    try {
        $stmt = $pdo->prepare('INSERT INTO LICENCIA_LOG (idLicencia, idAdmin, idCliente, accion, descripcion, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $idLicencia, $idAdmin, $idCliente, $accion, $descripcion,
            $_SERVER['REMOTE_ADDR'] ?? '',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
    } catch (Exception $e) { /* no romper la app por un log */ }
}

function lsGetPlans() {
    return [
        'BASICO' => ['nombre' => 'Basico', 'icono' => 'rocket', 'color' => '#38BDF8'],
        'PROFESIONAL' => ['nombre' => 'Profesional', 'icono' => 'zap', 'color' => '#A855F7'],
        'ENTERPRISE' => ['nombre' => 'Enterprise', 'icono' => 'crown', 'color' => '#E94560']
    ];
}

/**
 * Get action from either GET param, JSON body, or POST fields
 */
function lsGetAction() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($contentType, 'application/json') !== false;
    $input = [];
    if ($isJson) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    }
    $action = trim($input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '');
    // For FormData uploads, merge POST fields into input
    if (!$isJson && !empty($_POST)) {
        $input = array_merge($_POST, $input);
    }
    return [$action, $input];
}

/**
 * Obtener configuración de pasarelas para el frontend
 */
function lsGetGatewayConfig() {
    return [
        'wompi' => [
            'enabled'  => wompiEnabled(),
            'public'   => wompiConfig()['public'],
            'checkout' => wompiConfig()['checkout'],
            'mode'     => WOMPI_MODE,
        ],
        'paypal' => [
            'enabled'  => paypalEnabled(),
            'client_id' => paypalConfig()['client_id'],
            'mode'     => PAYPAL_MODE,
        ],
    ];
}
