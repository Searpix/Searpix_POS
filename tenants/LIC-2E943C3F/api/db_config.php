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