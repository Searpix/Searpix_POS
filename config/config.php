<?php
/* ============================================================
   Comandix License Server — Configuración Central
   Secrets MUST come from environment/.env, never from frontend.
   ============================================================ */
if (!defined('LS_APP')) define('LS_APP', true);

function loadDotEnvFile($path) {
    static $loaded = [];
    if (isset($loaded[$path])) return;
    $loaded[$path] = true;
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '' || preg_match('/[^A-Z0-9_]/i', $key)) continue;
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value,-1)==='"') || ($value[0] === "'" && substr($value,-1)==="'"))) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) putenv($key . '=' . $value);
    }
}
loadDotEnvFile(dirname(__DIR__) . '/.env');

function envValue($key, $default = null) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

/* -------- Base de datos central -------- */
define('LS_DB_HOST', envValue('LS_DB_HOST', 'localhost'));
define('LS_DB_NAME', envValue('LS_DB_NAME', 'freakers_licenses'));
define('LS_DB_USER', envValue('LS_DB_USER', ''));
define('LS_DB_PASS', envValue('LS_DB_PASS', ''));

/* -------- Seguridad / Tokens -------- */
define('LS_SECRET', envValue('LS_SECRET', ''));
define('LS_TOKEN_HOURS', max(1, (int)envValue('LS_TOKEN_HOURS', 24)));

/* -------- Multi-tenant -------- */
define('LS_TENANT_DB_PREFIX', envValue('LS_TENANT_DB_PREFIX', 'searpox_pos_'));
define('LS_TENANT_TEMPLATE_DB', envValue('LS_TENANT_TEMPLATE_DB', 'freakers_pos'));
define('LS_TENANT_BASE_URL', rtrim(envValue('LS_TENANT_BASE_URL', 'http://localhost/tenants'), '/'));
define('LS_TENANT_STORAGE', envValue('LS_TENANT_STORAGE', dirname(__DIR__) . '/tenants'));
define('LS_TENANT_DB_HOST', envValue('LS_TENANT_DB_HOST', LS_DB_HOST));
define('LS_TENANT_DB_ADMIN_USER', envValue('LS_TENANT_DB_ADMIN_USER', LS_DB_USER));
define('LS_TENANT_DB_ADMIN_PASS', envValue('LS_TENANT_DB_ADMIN_PASS', LS_DB_PASS));
define('LS_TENANT_DB_USER_PREFIX', envValue('LS_TENANT_DB_USER_PREFIX', 'tenant_'));

/* -------- Empresa / URLs -------- */
define('LS_MONEDA', envValue('LS_MONEDA', 'COP'));
define('LS_WHATSAPP', envValue('LS_WHATSAPP', '573235636580'));
define('LS_BASE_URL', rtrim(envValue('LS_BASE_URL', 'http://localhost'), '/'));

if (LS_SECRET === '' && !defined('PHPUNIT_RUNNING')) {
    error_log('[Comandix][SECURITY] LS_SECRET no esta configurado. Configure .env antes de produccion.');
}

/* -------- Mail -------- */
define('LS_MAIL_ENABLED', filter_var(envValue('LS_MAIL_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN));
define('LS_MAIL_HOST', envValue('LS_MAIL_HOST', 'smtp.gmail.com'));
define('LS_MAIL_PORT', (int)envValue('LS_MAIL_PORT', 587));
define('LS_MAIL_SECURE', envValue('LS_MAIL_SECURE', 'tls'));
define('LS_MAIL_USER', envValue('LS_MAIL_USER', ''));
define('LS_MAIL_PASS', envValue('LS_MAIL_PASS', ''));
define('LS_MAIL_FROM', envValue('LS_MAIL_FROM', LS_MAIL_USER));
define('LS_MAIL_FROM_NAME', envValue('LS_MAIL_FROM_NAME', 'Comandix Licencias'));
define('LS_NOTIFY_EMAIL', envValue('LS_NOTIFY_EMAIL', LS_MAIL_FROM));

/* -------- Pasarelas -------- */
define('WOMPI_MODE', envValue('WOMPI_MODE', 'sandbox'));
define('WOMPI_SANDBOX_PUBLIC', envValue('WOMPI_SANDBOX_PUBLIC', ''));
define('WOMPI_SANDBOX_PRIVATE', envValue('WOMPI_SANDBOX_PRIVATE', ''));
define('WOMPI_SANDBOX_INTEGRITY', envValue('WOMPI_SANDBOX_INTEGRITY', ''));
define('WOMPI_SANDBOX_EVENTS', envValue('WOMPI_SANDBOX_EVENTS', ''));
define('WOMPI_PROD_PUBLIC', envValue('WOMPI_PROD_PUBLIC', ''));
define('WOMPI_PROD_PRIVATE', envValue('WOMPI_PROD_PRIVATE', ''));
define('WOMPI_PROD_INTEGRITY', envValue('WOMPI_PROD_INTEGRITY', ''));
define('WOMPI_PROD_EVENTS', envValue('WOMPI_PROD_EVENTS', ''));

define('PAYPAL_MODE', envValue('PAYPAL_MODE', 'sandbox'));
define('PAYPAL_SANDBOX_CLIENT_ID', envValue('PAYPAL_SANDBOX_CLIENT_ID', ''));
define('PAYPAL_SANDBOX_SECRET', envValue('PAYPAL_SANDBOX_SECRET', ''));
define('PAYPAL_PROD_CLIENT_ID', envValue('PAYPAL_PROD_CLIENT_ID', ''));
define('PAYPAL_PROD_SECRET', envValue('PAYPAL_PROD_SECRET', ''));
define('PAYPAL_COP_TO_USD', (float)envValue('PAYPAL_COP_TO_USD', 4000));

function wompiConfig() {
    if (WOMPI_MODE === 'production') {
        return [
            'mode'      => 'production',
            'public'    => WOMPI_PROD_PUBLIC,
            'private'   => WOMPI_PROD_PRIVATE,
            'integrity' => WOMPI_PROD_INTEGRITY,
            'events'    => WOMPI_PROD_EVENTS,
            'api'       => 'https://production.wompi.co/v1',
            'checkout'  => 'https://checkout.wompi.co/p/',
        ];
    }
    return [
        'mode'      => 'sandbox',
        'public'    => WOMPI_SANDBOX_PUBLIC,
        'private'   => WOMPI_SANDBOX_PRIVATE,
        'integrity' => WOMPI_SANDBOX_INTEGRITY,
        'events'    => WOMPI_SANDBOX_EVENTS,
        'api'       => 'https://sandbox.wompi.co/v1',
        'checkout'  => 'https://checkout.wompi.co/p/',
    ];
}

function paypalConfig() {
    if (PAYPAL_MODE === 'production') {
        return [
            'mode'      => 'production',
            'client_id' => PAYPAL_PROD_CLIENT_ID,
            'secret'    => PAYPAL_PROD_SECRET,
            'api'       => 'https://api-m.paypal.com',
        ];
    }
    return [
        'mode'      => 'sandbox',
        'client_id' => PAYPAL_SANDBOX_CLIENT_ID,
        'secret'    => PAYPAL_SANDBOX_SECRET,
        'api'       => 'https://api-m.sandbox.paypal.com',
    ];
}

/* ¿Las pasarelas están configuradas con llaves reales? */
function wompiEnabled() {
    $c = wompiConfig();
    return !empty($c['public']) && !empty($c['public']);
}
function paypalEnabled() {
    $c = paypalConfig();
    return !empty($c['client_id']) && !empty($c['client_id']);
}
