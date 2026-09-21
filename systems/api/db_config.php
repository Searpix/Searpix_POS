<?php
/* FREAKERS POS - tenant-local environment loader.
   Production: this file reads ../.env; never commit real credentials. */
function posLoadEnv($path) {
    static $loaded = [];
    if (isset($loaded[$path])) return;
    $loaded[$path]=true;
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line=trim($line);
        if($line===''||$line[0]==='#'||strpos($line,'=')===false)continue;
        [$k,$v]=array_map('trim',explode('=',$line,2));
        if(!preg_match('/^[A-Z0-9_]+$/',$k))continue;
        if(strlen($v)>=2&&(($v[0]==='"'&&substr($v,-1)==='"')||($v[0]==="'"&&substr($v,-1)==="'"))) $v=substr($v,1,-1);
        if(getenv($k)===false)putenv($k.'='.$v);
    }
}
posLoadEnv(dirname(__DIR__).'/.env');
function posEnv($key,$default=''){ $v=getenv($key); return ($v===false||$v==='')?$default:$v; }

define('DB_HOST',posEnv('DB_HOST','localhost'));
define('DB_NAME',posEnv('DB_NAME',''));
define('DB_USER',posEnv('DB_USER',''));
define('DB_PASS',posEnv('DB_PASS',''));
define('POS_SECRET',posEnv('POS_SECRET',''));
define('TENANT_ID',posEnv('TENANT_ID',''));
define('CENTRAL_API_URL',rtrim(posEnv('CENTRAL_API_URL',''),'/'));
define('TENANT_API_KEY',posEnv('TENANT_API_KEY',''));
define('SETUP_TOKEN',posEnv('SETUP_TOKEN',''));
define('SETUP_DB_HOST',posEnv('SETUP_DB_HOST',DB_HOST));
define('SETUP_DB_USER',posEnv('SETUP_DB_USER',DB_USER));
define('SETUP_DB_PASS',posEnv('SETUP_DB_PASS',DB_PASS));

if(DB_NAME===''||DB_USER===''||POS_SECRET===''){
    // Do not expose filesystem/DB details.
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exito'=>false,'mensaje'=>'Configuracion del POS incompleta.']);
    exit;
}
