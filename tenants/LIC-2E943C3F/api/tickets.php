<?php
/* FREAKERS POS - Puente seguro hacia el sistema central de tickets.
   El navegador NUNCA recibe TENANT_API_KEY. */
require_once 'config.php';

try {
    $sesion=requireAuth();
    $pdo=getConnection();
    $input=json_decode(file_get_contents('php://input'),true)??[];
    $action=trim($input['action']??$_GET['action']??'');
    $map=[
        'crear'=>'tenant_create',
        'listar'=>'tenant_list',
        'ver'=>'tenant_get',
        'responder'=>'tenant_reply',
        'tenant_create'=>'tenant_create',
        'tenant_list'=>'tenant_list',
        'tenant_get'=>'tenant_get',
        'tenant_reply'=>'tenant_reply'
    ];
    if(!isset($map[$action]))jsonOutput(['exito'=>false,'mensaje'=>'Accion de soporte no valida.']);
    $payload=$input;
    $payload['action']=$map[$action];
    $payload['usuario_id']=$sesion['idUsuario'];
    $payload['usuario_nombre']=$sesion['nombre']??$sesion['usuario']??'Usuario POS';

    $result=centralTicketRequest($payload);
    jsonOutput($result['body'],$result['status']);
}catch(Throwable $e){
    error_log('[Freakers][tenant-tickets] '.$e->getMessage());
    jsonOutput(['exito'=>false,'mensaje'=>'No se pudo conectar con soporte central.'],503);
}
function centralTicketRequest(array $payload){
    if(!defined('CENTRAL_API_URL')||CENTRAL_API_URL===''||!defined('TENANT_API_KEY')||TENANT_API_KEY===''){
        return ['status'=>503,'body'=>['exito'=>false,'mensaje'=>'El tenant no tiene configurado el canal central de soporte.']];
    }
    $ch=curl_init(rtrim(CENTRAL_API_URL,'/').'/tickets.php');
    if(!$ch)throw new RuntimeException('cURL no disponible.');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Tenant-Id'=>TENANT_ID,
            'X-Tenant-Key'=>TENANT_API_KEY
        ],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ]);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($raw===false||$err!=='')throw new RuntimeException('Error de comunicacion central.');
    $body=json_decode($raw,true);
    if(!is_array($body))$body=['exito'=>false,'mensaje'=>'Respuesta invalida del servidor central.'];
    return ['status'=>$code>=100?$code:502,'body'=>$body];
}
