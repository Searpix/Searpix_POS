<?php
/* ============================================================
   Comandix - Tickets centrales
   Portal: autenticacion Bearer
   Tenant POS: X-Tenant-Id + X-Tenant-Key (server-to-server)
   ============================================================ */
require_once __DIR__ . '/config.php';

try {
    $pdo=lsGetConnection();
    ticketsEnsureSchema($pdo);
    [$action,$input]=lsGetAction();

    if (in_array($action,['tenant_create','tenant_list','tenant_get','tenant_reply'],true)) {
        $tenant=tenantAuth($pdo);
        if ($action==='tenant_create') {
            $asunto=trim($input['asunto']??''); $mensaje=trim($input['mensaje']??'');
            $categoria=trim($input['categoria']??'GENERAL'); $prioridad='NORMAL'; // la prioridad la define el soporte
            $userId=trim($input['usuario_id']??''); $userName=trim($input['usuario_nombre']??'');
            if($asunto===''||$mensaje===''||$userId==='') lsJsonOutput(['exito'=>false,'mensaje'=>'Datos incompletos.'],422);
            $id=lsGenerarId('TKT-');
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO TICKETS (idTicket,idCliente,idTenant,tenantUserId,tenantUserName,origen,asunto,categoria,prioridad,estado,creado,actualizado)
                VALUES (?,NULL,?,?,?,?,?,?,?,'ABIERTO',NOW(),NOW())")
                ->execute([$id,$tenant['idTenant'],$userId,$userName,'TENANT',$asunto,$categoria,$prioridad]);
            $pdo->prepare("INSERT INTO TICKET_MENSAJES (idTicket,autorTipo,autorId,mensaje,creado) VALUES (?,'TENANT_USER',?,?,NOW())")
                ->execute([$id,$userId,$mensaje]);
            $pdo->commit();
            lsLog($pdo,'TENANT_TICKET_CREATED',$asunto,null,null,null);
            lsJsonOutput(['exito'=>true,'idTicket'=>$id,'mensaje'=>'Ticket creado.']);
        }
        if($action==='tenant_list'){
            $userId=trim($input['usuario_id']??'');
            $stmt=$pdo->prepare("SELECT t.*, COALESCE(m.mensajes,0) mensajes
                FROM TICKETS t
                LEFT JOIN (SELECT idTicket,COUNT(*) mensajes FROM TICKET_MENSAJES GROUP BY idTicket) m ON m.idTicket=t.idTicket
                WHERE t.idTenant=? AND (?='' OR t.tenantUserId=?) ORDER BY t.actualizado DESC LIMIT 200");
            $stmt->execute([$tenant['idTenant'],$userId,$userId]);
            lsJsonOutput(['exito'=>true,'tickets'=>$stmt->fetchAll()]);
        }
        if($action==='tenant_get'){
            $id=trim($input['idTicket']??''); $userId=trim($input['usuario_id']??'');
            $stmt=$pdo->prepare("SELECT * FROM TICKETS WHERE idTicket=? AND idTenant=? AND (?='' OR tenantUserId=?)");
            $stmt->execute([$id,$tenant['idTenant'],$userId,$userId]);
            $ticket=$stmt->fetch(); if(!$ticket)lsJsonOutput(['exito'=>false,'mensaje'=>'Ticket no encontrado.'],404);
            $stmt=$pdo->prepare('SELECT autorTipo,autorId,mensaje,adjunto_url,adjunto_nombre,adjunto_tipo,creado FROM TICKET_MENSAJES WHERE idTicket=? ORDER BY id ASC');
            $stmt->execute([$id]);
            lsJsonOutput(['exito'=>true,'ticket'=>$ticket,'mensajes'=>$stmt->fetchAll(),'whatsapp'=>((int)($ticket['escalado']??0)===1?LS_WHATSAPP:null)]);
        }
        if($action==='tenant_reply'){
            $id=trim($input['idTicket']??''); $msg=trim($input['mensaje']??''); $userId=trim($input['usuario_id']??'');
            if($msg==='')lsJsonOutput(['exito'=>false,'mensaje'=>'El mensaje no puede estar vacio.'],422);
            $stmt=$pdo->prepare('SELECT estado FROM TICKETS WHERE idTicket=? AND idTenant=? AND tenantUserId=?');
            $stmt->execute([$id,$tenant['idTenant'],$userId]); $est=$stmt->fetchColumn(); if($est===false)lsJsonOutput(['exito'=>false,'mensaje'=>'Ticket no encontrado.'],404);
            if($est==='RESUELTO')lsJsonOutput(['exito'=>false,'mensaje'=>'Este ticket esta marcado como resuelto. No se pueden enviar mas mensajes.'],409);
            $pdo->prepare("INSERT INTO TICKET_MENSAJES (idTicket,autorTipo,autorId,mensaje,creado) VALUES (?,'TENANT_USER',?,?,NOW())")->execute([$id,$userId,$msg]);
            $pdo->prepare("UPDATE TICKETS SET estado='ABIERTO',actualizado=NOW() WHERE idTicket=?")->execute([$id]);
            lsJsonOutput(['exito'=>true,'mensaje'=>'Respuesta enviada.']);
        }
    }

    if($action==='crear' || $action==='client_create'){
        $auth=lsRequireAuth('client'); $asunto=trim($input['asunto']??''); $mensaje=trim($input['mensaje']??'');
        $categoria=trim($input['categoria']??'GENERAL'); $prioridad='NORMAL'; // la prioridad la define el soporte
        if($asunto==='')lsJsonOutput(['exito'=>false,'mensaje'=>'El asunto es obligatorio.'],422);
        $id=lsGenerarId('TKT-');
        $adjunto=ticketsSaveAdjunto($id);
        if($mensaje===''&&!$adjunto)lsJsonOutput(['exito'=>false,'mensaje'=>'Escribe un mensaje o adjunta un archivo.'],422);
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO TICKETS (idTicket,idCliente,origen,asunto,categoria,prioridad,estado,creado,actualizado) VALUES (?,?,'PORTAL',?,?,?,'ABIERTO',NOW(),NOW())")
            ->execute([$id,$auth['id'],$asunto,$categoria,$prioridad]);
        ticketsInsertMensaje($pdo,$id,'CLIENTE',$auth['id'],$mensaje,$adjunto);
        $pdo->commit(); lsLog($pdo,'TICKET_CREADO',$asunto,null,null,$auth['id']);
        lsJsonOutput(['exito'=>true,'idTicket'=>$id,'mensaje'=>'Ticket creado.']);
    }
    if($action==='client_list'){
        $auth=lsRequireAuth('client');
        $stmt=$pdo->prepare('SELECT t.*,COALESCE(m.mensajes,0) mensajes FROM TICKETS t
                LEFT JOIN (SELECT idTicket,COUNT(*) mensajes FROM TICKET_MENSAJES GROUP BY idTicket) m ON m.idTicket=t.idTicket
                WHERE t.idCliente=? ORDER BY t.actualizado DESC LIMIT 200');
        $stmt->execute([$auth['id']]); lsJsonOutput(['exito'=>true,'tickets'=>$stmt->fetchAll()]);
    }
    if($action==='client_get'){
        $auth=lsRequireAuth('client'); $id=trim($input['idTicket']??'');
        $stmt=$pdo->prepare('SELECT * FROM TICKETS WHERE idTicket=? AND idCliente=?');$stmt->execute([$id,$auth['id']]);$ticket=$stmt->fetch();
        if(!$ticket)lsJsonOutput(['exito'=>false,'mensaje'=>'Ticket no encontrado.'],404);
        $stmt=$pdo->prepare('SELECT autorTipo,autorId,mensaje,adjunto_url,adjunto_nombre,adjunto_tipo,creado FROM TICKET_MENSAJES WHERE idTicket=? ORDER BY id ASC');$stmt->execute([$id]);
        lsJsonOutput(['exito'=>true,'ticket'=>$ticket,'mensajes'=>$stmt->fetchAll(),'whatsapp'=>((int)($ticket['escalado']??0)===1?LS_WHATSAPP:null)]);
    }
    if($action==='client_reply'){
        $auth=lsRequireAuth('client');$id=trim($input['idTicket']??'');$msg=trim($input['mensaje']??'');
        $stmt=$pdo->prepare('SELECT estado FROM TICKETS WHERE idTicket=? AND idCliente=?');$stmt->execute([$id,$auth['id']]);
        $est=$stmt->fetchColumn(); if($est===false)lsJsonOutput(['exito'=>false,'mensaje'=>'Ticket no encontrado.'],404);
        if($est==='RESUELTO')lsJsonOutput(['exito'=>false,'mensaje'=>'Este ticket esta marcado como resuelto. No se pueden enviar mas mensajes.'],409);
        $adjunto=ticketsSaveAdjunto($id);
        if($msg===''&&!$adjunto)lsJsonOutput(['exito'=>false,'mensaje'=>'Escribe un mensaje o adjunta un archivo.'],422);
        ticketsInsertMensaje($pdo,$id,'CLIENTE',$auth['id'],$msg,$adjunto);
        $pdo->prepare("UPDATE TICKETS SET estado='ABIERTO',actualizado=NOW() WHERE idTicket=?")->execute([$id]);
        lsJsonOutput(['exito'=>true,'mensaje'=>'Respuesta enviada.']);
    }

    if($action==='admin_list'){
        $auth=lsRequireAuth('admin');$estado=strtoupper(trim($input['estado']??''));
        $sql="SELECT t.*,COALESCE(c.nombre,t.tenantUserName) cliente,c.email clienteEmail,
            t.idTenant,COALESCE(t.origen,'PORTAL') origen,
            COALESCE(m.mensajes,0) mensajes
            FROM TICKETS t
            LEFT JOIN (SELECT idTicket,COUNT(*) mensajes FROM TICKET_MENSAJES GROUP BY idTicket) m ON m.idTicket=t.idTicket LEFT JOIN CLIENTES c ON c.idCliente=t.idCliente";
        $params=[];
        if(in_array($estado,['ABIERTO','EN_PROCESO','RESUELTO'],true)){$sql.=' WHERE t.estado=?';$params[]=$estado;}
        $sql.=' ORDER BY t.actualizado DESC LIMIT 500';
        $stmt=$pdo->prepare($sql);$stmt->execute($params);lsJsonOutput(['exito'=>true,'tickets'=>$stmt->fetchAll()]);
    }
    if($action==='admin_get'){
        lsRequireAuth('admin');$id=trim($input['idTicket']??'');
        $stmt=$pdo->prepare('SELECT t.*,COALESCE(c.nombre,t.tenantUserName) cliente,c.email clienteEmail FROM TICKETS t LEFT JOIN CLIENTES c ON c.idCliente=t.idCliente WHERE t.idTicket=?');
        $stmt->execute([$id]);$ticket=$stmt->fetch();if(!$ticket)lsJsonOutput(['exito'=>false,'mensaje'=>'Ticket no encontrado.'],404);
        $stmt=$pdo->prepare('SELECT autorTipo,autorId,mensaje,adjunto_url,adjunto_nombre,adjunto_tipo,creado FROM TICKET_MENSAJES WHERE idTicket=? ORDER BY id ASC');$stmt->execute([$id]);
        lsJsonOutput(['exito'=>true,'ticket'=>$ticket,'mensajes'=>$stmt->fetchAll()]);
    }
    if($action==='admin_reply'){
        $auth=lsRequireAuth('admin');$id=trim($input['idTicket']??'');$msg=trim($input['mensaje']??'');
        $stmt=$pdo->prepare('SELECT estado FROM TICKETS WHERE idTicket=?');$stmt->execute([$id]);$est=$stmt->fetchColumn();if($est===false)lsJsonOutput(['exito'=>false,'mensaje'=>'Ticket no encontrado.'],404);
        if($est==='RESUELTO')lsJsonOutput(['exito'=>false,'mensaje'=>'Este ticket esta marcado como resuelto. Reabrelo para poder responder.'],409);
        $adjunto=ticketsSaveAdjunto($id);
        if($msg===''&&!$adjunto)lsJsonOutput(['exito'=>false,'mensaje'=>'Escribe un mensaje o adjunta un archivo.'],422);
        ticketsInsertMensaje($pdo,$id,'ADMIN',$auth['id'],$msg,$adjunto);
        $pdo->prepare("UPDATE TICKETS SET estado='EN_PROCESO',actualizado=NOW() WHERE idTicket=?")->execute([$id]);
        lsJsonOutput(['exito'=>true,'mensaje'=>'Respuesta enviada.']);
    }
    if($action==='admin_update'){
        lsRequireAuth('admin');$id=trim($input['idTicket']??'');$sets=[];$params=[];
        $estado=strtoupper(trim($input['estado']??''));$prioridad=strtoupper(trim($input['prioridad']??''));
        if(in_array($estado,['ABIERTO','EN_PROCESO','RESUELTO'],true)){$sets[]='estado=?';$params[]=$estado;}
        if(in_array($prioridad,['BAJA','NORMAL','ALTA','URGENTE'],true)){$sets[]='prioridad=?';$params[]=$prioridad;}
        if(!$sets)lsJsonOutput(['exito'=>false,'mensaje'=>'Nada que actualizar.'],422);
        $sets[]='actualizado=NOW()';$params[]=$id;$pdo->prepare('UPDATE TICKETS SET '.implode(',',$sets).' WHERE idTicket=?')->execute($params);
        lsJsonOutput(['exito'=>true,'mensaje'=>'Ticket actualizado.']);
    }
    if($action==='admin_escalate'){
        $auth=lsRequireAuth('admin');$id=trim($input['idTicket']??'');$activar=((int)($input['activar']??1)===1?1:0);
        $stmt=$pdo->prepare('SELECT idTicket FROM TICKETS WHERE idTicket=?');$stmt->execute([$id]);if(!$stmt->fetchColumn())lsJsonOutput(['exito'=>false,'mensaje'=>'Ticket no encontrado.'],404);
        $pdo->prepare('UPDATE TICKETS SET escalado=?,actualizado=NOW() WHERE idTicket=?')->execute([$activar,$id]);
        if($activar)$pdo->prepare("INSERT INTO TICKET_MENSAJES (idTicket,autorTipo,autorId,mensaje,creado) VALUES (?,'ADMIN',?,?,NOW())")->execute([$id,$auth['id'],'Este caso se ha habilitado para atencion por WhatsApp.']);
        lsJsonOutput(['exito'=>true,'mensaje'=>'Ticket actualizado.','whatsapp'=>$activar?LS_WHATSAPP:null]);
    }
    lsJsonOutput(['exito'=>false,'mensaje'=>'Accion no valida.'],400);
} catch(Throwable $e) {
    if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[Comandix][tickets] '.$e->getMessage());
    lsJsonOutput(['exito'=>false,'mensaje'=>'Ocurrio un error procesando el ticket.'],500);
}

function tenantAuth($pdo) {
    $id=trim($_SERVER['HTTP_X_TENANT_ID']??'');
    $key=trim($_SERVER['HTTP_X_TENANT_KEY']??'');
    if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/',$id)||$key==='')lsJsonOutput(['exito'=>false,'mensaje'=>'Tenant no autenticado.'],401);
    $stmt=$pdo->prepare('SELECT idTenant,idLicencia,estado,api_key_hash FROM TENANTS WHERE idTenant=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();
    if(!$row||$row['estado']!=='ACTIVO'||!hash_equals($row['api_key_hash'],hash('sha256',$key)))lsJsonOutput(['exito'=>false,'mensaje'=>'Tenant no autorizado.'],403);
    return $row;
}
function ticketsEnsureSchema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS TICKETS (
        idTicket VARCHAR(50) PRIMARY KEY,idCliente VARCHAR(50) NULL,idTenant VARCHAR(64) NULL,
        tenantUserId VARCHAR(64) NULL,tenantUserName VARCHAR(200) NULL,origen VARCHAR(20) NOT NULL DEFAULT 'PORTAL',
        asunto VARCHAR(200) NOT NULL,categoria VARCHAR(50) DEFAULT 'GENERAL',
        prioridad ENUM('BAJA','NORMAL','ALTA','URGENTE') DEFAULT 'NORMAL',
        estado ENUM('ABIERTO','EN_PROCESO','RESUELTO') DEFAULT 'ABIERTO',escalado TINYINT(1) DEFAULT 0,
        creado DATETIME DEFAULT CURRENT_TIMESTAMP,actualizado DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cliente(idCliente),INDEX idx_tenant(idTenant,tenantUserId),INDEX idx_estado_actualizado(estado,actualizado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach([
        "ALTER TABLE TICKETS MODIFY idCliente VARCHAR(50) NULL",
        "ALTER TABLE TICKETS ADD COLUMN idTenant VARCHAR(64) NULL",
        "ALTER TABLE TICKETS ADD COLUMN tenantUserId VARCHAR(64) NULL",
        "ALTER TABLE TICKETS ADD COLUMN tenantUserName VARCHAR(200) NULL",
        "ALTER TABLE TICKETS ADD COLUMN origen VARCHAR(20) NOT NULL DEFAULT 'PORTAL'",
        "ALTER TABLE TICKETS ADD COLUMN escalado TINYINT(1) DEFAULT 0",
        "ALTER TABLE TICKETS ADD INDEX idx_tenant (idTenant,tenantUserId)",
        "ALTER TABLE TICKETS ADD INDEX idx_estado_actualizado (estado,actualizado)"
    ] as $sql){try{$pdo->exec($sql);}catch(Throwable $e){}}
    try {
        $pdo->exec("ALTER TABLE TICKETS MODIFY estado ENUM('ABIERTO','RESPONDIDO','CERRADO','EN_PROCESO','RESUELTO') NOT NULL DEFAULT 'ABIERTO'");
        $pdo->exec("UPDATE TICKETS SET estado='EN_PROCESO' WHERE estado='RESPONDIDO'");
        $pdo->exec("UPDATE TICKETS SET estado='RESUELTO' WHERE estado='CERRADO'");
        $pdo->exec("ALTER TABLE TICKETS MODIFY estado ENUM('ABIERTO','EN_PROCESO','RESUELTO') NOT NULL DEFAULT 'ABIERTO'");
    } catch(Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS TICKET_MENSAJES (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,idTicket VARCHAR(50) NOT NULL,autorTipo VARCHAR(20) NOT NULL,
        autorId VARCHAR(64) NOT NULL,mensaje TEXT NOT NULL,
        adjunto_url VARCHAR(300) NULL,adjunto_nombre VARCHAR(200) NULL,adjunto_tipo VARCHAR(100) NULL,
        creado DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ticket_creado(idTicket,creado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try{$pdo->exec("ALTER TABLE TICKET_MENSAJES MODIFY autorTipo VARCHAR(20) NOT NULL");}catch(Throwable $e){}
    // Permitir mensajes vacios cuando solo se envia un adjunto
    try{$pdo->exec("ALTER TABLE TICKET_MENSAJES MODIFY mensaje TEXT NULL");}catch(Throwable $e){}
    foreach([
        "ALTER TABLE TICKET_MENSAJES ADD COLUMN adjunto_url VARCHAR(300) NULL",
        "ALTER TABLE TICKET_MENSAJES ADD COLUMN adjunto_nombre VARCHAR(200) NULL",
        "ALTER TABLE TICKET_MENSAJES ADD COLUMN adjunto_tipo VARCHAR(100) NULL"
    ] as $sql){try{$pdo->exec($sql);}catch(Throwable $e){}}
}

/* Guarda el archivo adjunto opcional (campo FormData 'adjunto').
   Devuelve ['url','nombre','tipo'] o null si no hay adjunto.
   Termina la peticion con error si el adjunto es invalido. */
function ticketsSaveAdjunto($idTicket) {
    if (empty($_FILES['adjunto']) || !isset($_FILES['adjunto']['error']) || $_FILES['adjunto']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES['adjunto'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        lsJsonOutput(['exito'=>false,'mensaje'=>'No se pudo subir el archivo adjunto.'],422);
    }
    if ((int)$f['size'] <= 0 || (int)$f['size'] > 8*1024*1024) {
        lsJsonOutput(['exito'=>false,'mensaje'=>'El archivo supera el limite de 8 MB.'],422);
    }
    $permitidos = [
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp',
        'pdf'=>'application/pdf','txt'=>'text/plain','csv'=>'text/csv',
        'doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip'=>'application/zip'
    ];
    $orig = (string)$f['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!isset($permitidos[$ext])) {
        lsJsonOutput(['exito'=>false,'mensaje'=>'Tipo de archivo no permitido. Solo imagenes, PDF, documentos de oficina, txt, csv o zip.'],415);
    }
    $safeId = preg_replace('/[^A-Za-z0-9_-]/','',(string)$idTicket);
    if ($safeId === '') { lsJsonOutput(['exito'=>false,'mensaje'=>'Ticket no valido para adjuntar.'],400); }
    $dir = __DIR__ . '/../storage/tickets/' . $safeId;
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_dir($dir) || !is_writable($dir)) {
        lsJsonOutput(['exito'=>false,'mensaje'=>'No se pudo preparar el almacenamiento del adjunto.'],500);
    }
    $nombreArchivo = bin2hex(random_bytes(8)) . '.' . $ext;
    $destino = $dir . '/' . $nombreArchivo;
    if (!move_uploaded_file($f['tmp_name'], $destino)) {
        lsJsonOutput(['exito'=>false,'mensaje'=>'No se pudo guardar el adjunto.'],500);
    }
    $url = 'ticket_adjunto.php?ticket=' . rawurlencode($safeId) . '&file=' . rawurlencode($nombreArchivo);
    $nombreVisible = mb_substr(preg_replace('/[\x00-\x1F\x7F]/','',$orig), 0, 200);
    if ($nombreVisible === '') { $nombreVisible = 'adjunto.' . $ext; }
    return ['url'=>$url,'nombre'=>$nombreVisible,'tipo'=>$permitidos[$ext]];
}

/* Devuelve el estado actual del ticket (o null si no existe). */
function ticketsEstado($pdo,$idTicket){
    $st=$pdo->prepare('SELECT estado FROM TICKETS WHERE idTicket=? LIMIT 1');
    $st->execute([$idTicket]);
    $e=$st->fetchColumn();
    return $e===false?null:$e;
}

/* Inserta un mensaje (con adjunto opcional) en el hilo del ticket. */
function ticketsInsertMensaje($pdo,$idTicket,$autorTipo,$autorId,$mensaje,$adjunto){
    $pdo->prepare("INSERT INTO TICKET_MENSAJES (idTicket,autorTipo,autorId,mensaje,adjunto_url,adjunto_nombre,adjunto_tipo,creado) VALUES (?,?,?,?,?,?,?,NOW())")
        ->execute([$idTicket,$autorTipo,$autorId,($mensaje===''?null:$mensaje),
            $adjunto['url']??null,$adjunto['nombre']??null,$adjunto['tipo']??null]);
}
