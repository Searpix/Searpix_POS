<?php
/* ============================================
   FREAKERS POS - Fix FK + Repopulate Data
   ============================================
   This script:
   1. Fixes FK constraints (ON DELETE SET NULL, allow NULL idUsuario)
   2. Checks if CATEGORIAS/PRODUCTOS are empty and repopulates them
   3. Verifies USUARIOS exist
   
   Use this if you ALREADY have a DB and don't want to drop it.
   For a full clean install, use setup.php instead.
   
   Run via browser: http://localhost/sistema-central/api/fix_fk_constraint.php
   ============================================ */

require_once 'config.php';

// Bloqueo de seguridad: script de mantenimiento destructivo.
require_once __DIR__ . '/_setup_guard.php';

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html><html><head><title>Fix FK + Repopulate</title>';
echo '<style>';
echo '*{margin:0;padding:0;box-sizing:border-box}';
echo 'body{font-family:"Inter",monospace;background:#1a1a2e;color:#e0e0e0;padding:30px}';
echo 'h2{font-size:1.3rem;font-weight:900;color:#6c3ce1;margin-bottom:16px}';
echo 'h3{font-size:1rem;font-weight:700;color:#e94560;margin:16px 0 8px}';
echo '.ok{padding:8px 14px;margin:4px 0;border-radius:8px;background:rgba(74,222,128,0.08);border-left:3px solid #4ade80;font-size:0.85rem}';
echo '.err{padding:8px 14px;margin:4px 0;border-radius:8px;background:rgba(239,68,68,0.08);border-left:3px solid #ef4444;font-size:0.85rem}';
echo '.info{padding:8px 14px;margin:4px 0;border-radius:8px;background:rgba(56,189,248,0.08);border-left:3px solid #38bdf8;font-size:0.85rem}';
echo '.warn{padding:8px 14px;margin:4px 0;border-radius:8px;background:rgba(251,191,36,0.08);border-left:3px solid #fbbf24;font-size:0.85rem}';
echo 'a.btn{display:inline-block;padding:12px 28px;border-radius:12px;background:#6c3ce1;color:#fff;text-decoration:none;font-weight:700;font-size:0.95rem;margin-top:12px}';
echo '</style></head><body>';
echo '<h2>FREAKERS POS - Fix FK + Repoblar Datos</h2>';

try {
    $pdo = getConnection();
    echo '<div class="info">Conexion a DB exitosa.</div>';

    // ===== FIX FK CONSTRAINTS =====
    $tables_to_fix = ['MOVIMIENTOS', 'ORDENES', 'PAGOS'];
    
    foreach ($tables_to_fix as $table) {
        echo '<h3>Fix ' . $table . '</h3>';
        
        // Allow NULL on idUsuario
        try {
            $pdo->exec('ALTER TABLE ' . $table . ' MODIFY COLUMN idUsuario VARCHAR(50) DEFAULT NULL');
            echo '<div class="ok">idUsuario ahora permite NULL en ' . $table . '</div>';
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already') !== false) {
                echo '<div class="info">Columna idUsuario ya permite NULL en ' . $table . '</div>';
            } else {
                echo '<div class="err">Error modificando columna: ' . $e->getMessage() . '</div>';
            }
        }

        // Drop and recreate FK constraints
        try {
            $stmt = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "' AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
            $fks = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $droppedUserFK = false;
            foreach ($fks as $fkName) {
                // Only drop FKs that reference idUsuario
                $stmt2 = $pdo->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "' AND CONSTRAINT_NAME = '" . $fkName . "'");
                $colInfo = $stmt2->fetch();
                if ($colInfo && strtolower($colInfo['COLUMN_NAME']) === 'idusuario') {
                    $pdo->exec('ALTER TABLE ' . $table . ' DROP FOREIGN KEY ' . $fkName);
                    echo '<div class="ok">FK ' . $fkName . ' eliminada de ' . $table . '</div>';
                    $droppedUserFK = true;
                }
            }
            
            if ($droppedUserFK) {
                $fkName = 'fk_' . strtolower($table) . '_usuario';
                $pdo->exec('ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $fkName . ' FOREIGN KEY (idUsuario) REFERENCES USUARIOS(idUsuario) ON DELETE SET NULL');
                echo '<div class="ok">Nueva FK con ON DELETE SET NULL creada en ' . $table . '</div>';
            } else {
                // Check if the FK already exists with correct settings
                $stmt3 = $pdo->query("SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "' AND COLUMN_NAME = 'idUsuario'");
                $existing = $stmt3->fetch();
                if ($existing && $existing['DELETE_RULE'] === 'SET NULL') {
                    echo '<div class="info">FK con ON DELETE SET NULL ya existe en ' . $table . '</div>';
                } else {
                    echo '<div class="warn">No se encontro FK de idUsuario en ' . $table . '. Creando...</div>';
                    $fkName = 'fk_' . strtolower($table) . '_usuario';
                    $pdo->exec('ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $fkName . ' FOREIGN KEY (idUsuario) REFERENCES USUARIOS(idUsuario) ON DELETE SET NULL');
                    echo '<div class="ok">Nueva FK creada en ' . $table . '</div>';
                }
            }
        } catch (Exception $e) {
            echo '<div class="err">Error recreando FK en ' . $table . ': ' . $e->getMessage() . '</div>';
        }
    }

    // ===== VERIFY / FIX USUARIOS =====
    echo '<h3>Verificar Usuarios</h3>';
    $stmt = $pdo->query('SELECT idUsuario, usuario, nombre, rol, estado FROM USUARIOS');
    $users = $stmt->fetchAll();
    
    if (empty($users)) {
        echo '<div class="err">NO HAY USUARIOS! Insertando defaults...</div>';
        $adminPassword = getenv('SETUP_POS_ADMIN_PASSWORD') ?: bin2hex(random_bytes(16));
        $waiterPassword = getenv('SETUP_POS_WAITER_PASSWORD') ?: bin2hex(random_bytes(16));
        $st = $pdo->prepare('INSERT INTO USUARIOS (idUsuario, usuario, clave, nombre, rol, estado) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute(['U001','SEARPIX',password_hash($adminPassword,PASSWORD_DEFAULT),'Diego','ADMIN','ACTIVO']);
        $st->execute(['U002','FREAKERS',password_hash($waiterPassword,PASSWORD_DEFAULT),'Freakers','MESERO','ACTIVO']);
        echo '<div class="ok">Usuarios de recuperación creados con contraseñas aleatorias/variables de entorno. No se imprimen credenciales.</div>';
    } else {
        echo '<div class="ok">Usuarios existentes: ' . count($users) . '</div>';
        foreach ($users as $u) {
            echo '<div class="info">  ' . $u['idUsuario'] . ' / ' . $u['usuario'] . ' / ' . $u['nombre'] . ' / ' . $u['rol'] . '</div>';
        }
    }

    // ===== CHECK / REPOPULATE CATEGORIAS =====
    echo '<h3>Verificar Categorias</h3>';
    $cnt = $pdo->query('SELECT COUNT(*) FROM CATEGORIAS')->fetchColumn();
    
    if ($cnt == 0) {
        echo '<div class="err">CATEGORIAS VACIA! Repoblando...</div>';
        
        $categorias = [
            ['CAT-ENT','Entradas','https://cdn-icons-png.flaticon.com/512/5306/5306580.png','ACTIVO'],
            ['CAT-BEB','Bebidas','https://cdn-icons-png.flaticon.com/512/2391/2391701.png','ACTIVO'],
            ['CAT-GAS','Gaseosas','https://cdn-icons-png.flaticon.com/512/3050/3050130.png','ACTIVO'],
            ['CAT-JUG','Jugos Naturales','https://cdn-icons-png.flaticon.com/512/2442/2442019.png','ACTIVO'],
            ['CAT-LIM','Limonadas',NULL,'ACTIVO'],
            ['CAT-CER','Cervezas','https://cdn-icons-png.flaticon.com/512/3728/3728021.png','ACTIVO'],
            ['CAT-MIC','Micheladas','https://cdn-icons-png.flaticon.com/512/7924/7924161.png','ACTIVO'],
            ['CAT-COC','Cocteles','https://cdn-icons-png.flaticon.com/512/7285/7285889.png','ACTIVO'],
            ['CAT-HAM','Hamburguesas','https://cdn-icons-png.flaticon.com/512/3075/3075977.png','ACTIVO'],
            ['CAT-BAB','Baby Burgers','https://i.ibb.co/whWMcfhq/images.png','ACTIVO'],
            ['CAT-HF','HamFreaks','https://cdn-icons-png.flaticon.com/512/2278/2278992.png','ACTIVO'],
            ['CAT-ESP','Especiales','https://i.ibb.co/39bHjfNc/freakers-png.png','ACTIVO'],
            ['CAT-PER','Perros','https://cdn-icons-png.flaticon.com/512/2674/2674083.png','ACTIVO'],
            ['CAT-PAP','Papas','https://cdn-icons-png.flaticon.com/512/1057/1057356.png','ACTIVO'],
            ['CAT-ADI','Adiciones','https://cdn-icons-png.flaticon.com/512/9224/9224691.png','ACTIVO']
        ];
        $stmt = $pdo->prepare('INSERT INTO CATEGORIAS VALUES (?, ?, ?, ?)');
        foreach ($categorias as $c) $stmt->execute($c);
        echo '<div class="ok">' . count($categorias) . ' categorias insertadas</div>';
    } else {
        echo '<div class="ok">Categorias OK: ' . $cnt . ' registros</div>';
    }

    // ===== CHECK / REPOPULATE PRODUCTOS =====
    echo '<h3>Verificar Productos</h3>';
    $cnt = $pdo->query('SELECT COUNT(*) FROM PRODUCTOS')->fetchColumn();
    
    if ($cnt == 0) {
        echo '<div class="err">PRODUCTOS VACIA! Repoblando...</div>';
        
        $productos = [
            ['PROD-001','CAT-ENT','Nachos con Guacamole',8000,'https://i.ibb.co/39bHjfNc/freakers-png.png','ACTIVO'],
            ['PROD-002','CAT-ENT','Patacones con Guacamole',8000,'https://cdn-icons-png.flaticon.com/512/5306/5306580.png','ACTIVO'],
            ['PROD-003','CAT-ENT','Quesadilla x3',10000,NULL,'ACTIVO'],
            ['PROD-004','CAT-ENT','Patacones con Carne x6',15000,NULL,'ACTIVO'],
            ['PROD-005','CAT-BEB','Frape Colombiano',12000,NULL,'ACTIVO'],
            ['PROD-006','CAT-GAS','Coca-Cola 400ml',5000,NULL,'ACTIVO'],
            ['PROD-007','CAT-LIM','Limonada de Coco',9000,NULL,'ACTIVO'],
            ['PROD-008','CAT-LIM','Limonada de Cereza',9000,NULL,'ACTIVO'],
            ['PROD-009','CAT-LIM','Limonada Mango Biche',9000,NULL,'ACTIVO'],
            ['PROD-010','CAT-LIM','Limonada Natural',7000,NULL,'ACTIVO'],
            ['PROD-011','CAT-JUG','Jugo Natural en Agua: Fresa',6000,NULL,'ACTIVO'],
            ['PROD-012','CAT-JUG','Jugo Natural en Agua: Mora',6000,NULL,'ACTIVO'],
            ['PROD-013','CAT-JUG','Jugo Natural en Agua: Mango',6000,NULL,'ACTIVO'],
            ['PROD-014','CAT-JUG','Jugo Natural en Agua: Maracuya',6000,NULL,'ACTIVO'],
            ['PROD-015','CAT-JUG','Jugo Natural en Agua: Mandarina',6000,NULL,'ACTIVO'],
            ['PROD-016','CAT-JUG','Jugo Natural en Agua: Lulo',6000,NULL,'ACTIVO'],
            ['PROD-017','CAT-JUG','Jugo Natural en Leche: Fresa',8000,NULL,'ACTIVO'],
            ['PROD-018','CAT-JUG','Jugo Natural en Leche: Mora',8000,NULL,'ACTIVO'],
            ['PROD-019','CAT-JUG','Jugo Natural en Leche: Mango',8000,NULL,'ACTIVO'],
            ['PROD-020','CAT-JUG','Jugo Natural en Leche: Maracuya',8000,NULL,'ACTIVO'],
            ['PROD-021','CAT-CER','Cerveza Aguila',4000,NULL,'ACTIVO'],
            ['PROD-022','CAT-CER','Cerveza Poker',4000,NULL,'ACTIVO'],
            ['PROD-023','CAT-CER','Cerveza Club Colombia',5000,NULL,'ACTIVO'],
            ['PROD-024','CAT-CER','Cerveza Corona',8000,NULL,'ACTIVO'],
            ['PROD-025','CAT-MIC','Michelada Clasica',12000,NULL,'ACTIVO'],
            ['PROD-026','CAT-MIC','Michelada de Mango',14000,NULL,'ACTIVO'],
            ['PROD-027','CAT-MIC','Michelada de Fresa',14000,NULL,'ACTIVO'],
            ['PROD-028','CAT-COC','Coco Loco',18000,NULL,'ACTIVO'],
            ['PROD-029','CAT-COC','Margarita',16000,NULL,'ACTIVO'],
            ['PROD-030','CAT-HAM','Hamburguesa Clasica',18000,NULL,'ACTIVO'],
            ['PROD-031','CAT-HAM','Hamburguesa Doble',25000,NULL,'ACTIVO'],
            ['PROD-032','CAT-HAM','Hamburguesa BBQ',22000,NULL,'ACTIVO'],
            ['PROD-033','CAT-BAB','Baby Burger Sencilla',10000,NULL,'ACTIVO'],
            ['PROD-034','CAT-BAB','Baby Burger Queso',12000,NULL,'ACTIVO'],
            ['PROD-035','CAT-HF','HamFreaks Clasica',30000,'https://cdn-icons-png.flaticon.com/512/2278/2278992.png','ACTIVO'],
            ['PROD-036','CAT-HF','HamFreaks Doble',40000,NULL,'ACTIVO'],
            ['PROD-037','CAT-ESP','Freakers Especial',35000,'https://i.ibb.co/39bHjfNc/freakers-png.png','ACTIVO'],
            ['PROD-038','CAT-PER','Perro Sencillo',8000,NULL,'ACTIVO'],
            ['PROD-039','CAT-PER','Perro Doble',12000,NULL,'ACTIVO'],
            ['PROD-040','CAT-PER','Perro Choripapa',15000,NULL,'ACTIVO'],
            ['PROD-041','CAT-PAP','Papas Clasicas',8000,NULL,'ACTIVO'],
            ['PROD-042','CAT-PAP','Papas Cheddar',10000,NULL,'ACTIVO'],
            ['PROD-043','CAT-PAP','Papas Cargadas',14000,NULL,'ACTIVO'],
            ['PROD-044','CAT-ADI','Queso Extra',3000,NULL,'ACTIVO'],
            ['PROD-045','CAT-ADI','Carne Extra',5000,NULL,'ACTIVO'],
            ['PROD-046','CAT-ADI','Tocineta Extra',3000,NULL,'ACTIVO'],
            ['PROD-047','CAT-ADI','Huevo Extra',2000,NULL,'ACTIVO']
        ];
        $stmt = $pdo->prepare('INSERT INTO PRODUCTOS VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($productos as $p) $stmt->execute($p);
        echo '<div class="ok">' . count($productos) . ' productos insertados</div>';
    } else {
        echo '<div class="ok">Productos OK: ' . $cnt . ' registros</div>';
    }

    // ===== CHECK / REPOPULATE MESAS =====
    echo '<h3>Verificar Mesas</h3>';
    $cnt = $pdo->query('SELECT COUNT(*) FROM MESAS')->fetchColumn();
    
    if ($cnt == 0) {
        echo '<div class="err">MESAS VACIA! Repoblando...</div>';
        $mesas = [
            ['M1','1',4,'LIBRE','MESAS'],['M2','2',4,'LIBRE','MESAS'],['M3','3',4,'LIBRE','MESAS'],['M4','4',4,'LIBRE','MESAS'],
            ['M5','5',4,'LIBRE','MESAS'],['M6','6',4,'LIBRE','MESAS'],['M7','7',4,'LIBRE','MESAS'],['M8','8',4,'LIBRE','MESAS'],
            ['M9','9',4,'LIBRE','MESAS'],['M10','10',4,'LIBRE','MESAS'],['M11','11',4,'LIBRE','MESAS'],['M12','12',4,'OCUPADA','MESAS'],
            ['M13','13',4,'LIBRE','MESAS'],
            ['VIP1','VIP 1',2,'LIBRE','VIP'],['VIP2','VIP 2',2,'LIBRE','VIP'],['VIP3','VIP 3',2,'LIBRE','VIP'],
            ['BARRA1','BARRA 1',1,'LIBRE','BARRA'],['BARRA2','BARRA 2',1,'LIBRE','BARRA'],['BARRA3','BARRA 3',1,'LIBRE','BARRA'],['BARRA4','BARRA 4',1,'LIBRE','BARRA']
        ];
        $stmt = $pdo->prepare('INSERT INTO MESAS VALUES (?, ?, ?, ?, ?)');
        foreach ($mesas as $m) $stmt->execute($m);
        echo '<div class="ok">' . count($mesas) . ' mesas insertadas</div>';
    } else {
        echo '<div class="ok">Mesas OK: ' . $cnt . ' registros</div>';
    }

    // ===== CHECK / REPOPULATE CONFIGURACION =====
    echo '<h3>Verificar Configuracion</h3>';
    $cnt = $pdo->query('SELECT COUNT(*) FROM CONFIGURACION')->fetchColumn();
    
    if ($cnt == 0) {
        echo '<div class="err">CONFIGURACION VACIA! Repoblando...</div>';
        $config = [
            ['NOMBRE_NEGOCIO','Freakers'],['NOMBRE_RESTAURANTE','FREAKERS'],['RAZON_SOCIAL','BURGERS AND FAST FOOD'],
            ['NIT','1214722370'],['DIRECCION','Medellin, Colombia'],['TELEFONO','310 574 3129'],
            ['SUBTITULO','Burgers & Fast Food'],['LOGO','https://i.ibb.co/39bHjfNc/freakers-png.png'],
            ['COLOR_PRIMARIO','#6C3CE1'],['COLOR_SECUNDARIO','#1A1A2E'],['COLOR_ACENTO','#E94560'],
            ['COLOR_FONDO','#16213E'],['TIPOGRAFIA','Inter'],['VALOR_DOMICILIO','5000'],
            ['AUTO_IMPRIMIR','SI'],['MONEDA','COP'],['IMPUESTO','0'],
            ['PEDIDOS_COCINA','SI'],['WHATSAPP_NUMERO',''],['WHATSAPP_API_TOKEN',''],['WHATSAPP_WABA_ID','']
        ];
        $stmt = $pdo->prepare('INSERT INTO CONFIGURACION VALUES (?, ?)');
        foreach ($config as $c) $stmt->execute($c);
        echo '<div class="ok">' . count($config) . ' parametros insertados</div>';
    } else {
        echo '<div class="ok">Configuracion OK: ' . $cnt . ' parametros</div>';
    }

    // ===== CHECK / REPOPULATE APPS_DELIVERY =====
    echo '<h3>Verificar Apps Delivery</h3>';
    $cnt = $pdo->query('SELECT COUNT(*) FROM APPS_DELIVERY')->fetchColumn();
    
    if ($cnt == 0) {
        echo '<div class="err">APPS_DELIVERY VACIA! Repoblando...</div>';
        $apps = [
            ['APP-WHATSAPP','WhatsApp','https://cdn-icons-png.flaticon.com/512/1240/1240979.png',1,0.00,NULL,1,NULL,NULL],
            ['APP-DIDI','DidiFood','https://cdn-icons-png.flaticon.com/512/2920/2920349.png',1,20.00,NULL,2,NULL,NULL],
            ['APP-UBER','UberEats','https://cdn-icons-png.flaticon.com/512/3490/3490285.png',1,25.00,NULL,3,NULL,NULL],
            ['APP-RAPPI','Rappi','https://cdn-icons-png.flaticon.com/512/3490/3490400.png',1,22.00,NULL,4,NULL,NULL],
            ['APP-IFOOD','iFood','https://cdn-icons-png.flaticon.com/512/3046/3046921.png',1,18.00,NULL,5,NULL,NULL],
            ['APP-PEDIDOSYA','PedidosYa','https://cdn-icons-png.flaticon.com/512/3490/3490530.png',1,15.00,NULL,6,NULL,NULL]
        ];
        $stmt = $pdo->prepare('INSERT INTO APPS_DELIVERY VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($apps as $a) $stmt->execute($a);
        echo '<div class="ok">' . count($apps) . ' apps insertadas</div>';
    } else {
        echo '<div class="ok">Apps OK: ' . $cnt . ' registros</div>';
    }

    // ===== CHECK / REPOPULATE TICKET_CONFIG =====
    echo '<h3>Verificar Ticket Config</h3>';
    $cnt = $pdo->query('SELECT COUNT(*) FROM TICKET_CONFIG')->fetchColumn();
    
    if ($cnt == 0) {
        echo '<div class="err">TICKET_CONFIG VACIA! Repoblando...</div>';
        $tickets = [
            ['TKT-HEADER','HEADER',1,'{logo}',0,'{"alineacion":"centro","tamano":"grande"}'],
            ['TKT-NEGOCIO','NEGOCIO',1,'{negocio}\n{razon_social}\nNIT: {nit}\n{direccion} - {telefono}',1,'{"alineacion":"centro","tamano":"normal"}'],
            ['TKT-ORDEN','ORDEN',1,'Orden: {id_orden}\nFecha: {fecha}\nTipo: {tipo}\n{info_cliente}',2,'{"alineacion":"izquierda","tamano":"normal"}'],
            ['TKT-ITEMS','ITEMS',1,'{items_tabla}',3,'{"alineacion":"izquierda","tamano":"normal"}'],
            ['TKT-TOTALES','TOTALES',1,'Subtotal: {subtotal}\nDomicilio: {domicilio}\n----------------\nTOTAL: {total}',4,'{"alineacion":"derecha","tamano":"normal"}'],
            ['TKT-FOOTER','FOOTER',1,'Gracias por tu compra!\n{negocio}',5,'{"alineacion":"centro","tamano":"normal"}'],
            ['TKT-PUBLICIDAD','PUBLICIDAD',0,'Siguenos en @freakers\nInstagram: @freakersburger',6,'{"alineacion":"centro","tamano":"pequeno"}']
        ];
        $stmt = $pdo->prepare('INSERT INTO TICKET_CONFIG VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($tickets as $t) $stmt->execute($t);
        echo '<div class="ok">' . count($tickets) . ' bloques insertados</div>';
    } else {
        echo '<div class="ok">Ticket Config OK: ' . $cnt . ' registros</div>';
    }

    // ===== CHECK / REPOPULATE RESPUESTAS_RAPIDAS =====
    echo '<h3>Verificar Respuestas Rapidas</h3>';
    $cnt = $pdo->query('SELECT COUNT(*) FROM RESPUESTAS_RAPIDAS')->fetchColumn();
    
    if ($cnt == 0) {
        echo '<div class="err">RESPUESTAS_RAPIDAS VACIA! Repoblando...</div>';
        $respuestas = [
            ['RR-001','Confirmacion','Hola {cliente}! Tu pedido #{id_orden} ha sido confirmado. Productos: {productos}. Total: {total_domicilio}. {negocio}','{cliente},{productos},{total_domicilio},{id_orden},{negocio}',1,1],
            ['RR-002','En Camino','Hola {cliente}! Tu domicilio #{id_orden} ya va en camino. Llegara pronto a {direccion}. {negocio}','{cliente},{id_orden},{direccion},{negocio}',1,2],
            ['RR-003','Entregado','Hola {cliente}! Tu domicilio #{id_orden} fue entregado exitosamente. Gracias por pedir en {negocio}!','{cliente},{id_orden},{negocio}',1,3],
            ['RR-004','Cancelado','Hola {cliente}. Lamentamos informarte que tu domicilio #{id_orden} ha sido cancelado. Contactanos al {telefono}. {negocio}','{cliente},{id_orden},{telefono},{negocio}',1,4]
        ];
        $stmt = $pdo->prepare('INSERT INTO RESPUESTAS_RAPIDAS VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($respuestas as $r) $stmt->execute($r);
        echo '<div class="ok">' . count($respuestas) . ' respuestas insertadas</div>';
    } else {
        echo '<div class="ok">Respuestas OK: ' . $cnt . ' registros</div>';
    }

    // ===== FINAL VERIFICATION =====
    echo '<h3>Verificacion Final</h3>';
    
    $tables = ['USUARIOS','MESAS','CATEGORIAS','PRODUCTOS','CONFIGURACION','APPS_DELIVERY','TICKET_CONFIG','RESPUESTAS_RAPIDAS','ORDENES','DETALLE_ORDEN','PAGOS','MOVIMIENTOS','NOTIFICACIONES'];
    foreach ($tables as $t) {
        try {
            $cnt = $pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
            echo '<div class="info">' . $t . ': ' . $cnt . ' registros</div>';
        } catch (Exception $e) {
            echo '<div class="err">' . $t . ': TABLA NO EXISTE</div>';
        }
    }

    // Test FK - can we insert a movimiento now?
    try {
        $testMovId = 'MOV-TEST-' . time();
        $pdo->prepare('INSERT INTO MOVIMIENTOS (idMovimiento, fecha, idUsuario, accion, descripcion) VALUES (?, NOW(), ?, ?, ?)')->execute([$testMovId, 'U001', 'TEST_FIX', 'Test post-fix']);
        $pdo->prepare('DELETE FROM MOVIMIENTOS WHERE idMovimiento = ?')->execute([$testMovId]);
        echo '<div class="ok">Test INSERT en MOVIMIENTOS con U001: OK</div>';
    } catch (Exception $e) {
        echo '<div class="err">Test INSERT en MOVIMIENTOS FALLO: ' . $e->getMessage() . '</div>';
    }

    echo '<hr style="margin:20px 0;border-color:rgba(255,255,255,0.1)">';
    echo '<div class="ok" style="font-size:1.2rem;text-align:center;padding:20px">';
    echo 'FIX + REPOBLADO COMPLETADO!<br>';
    echo '<span style="font-size:0.85rem;color:rgba(255,255,255,0.5)">Credenciales definidas por el operador; no se muestran.</span>';
    echo '</div>';
    echo '<div style="text-align:center"><a class="btn" href="../index.html">Ir al Sistema &rarr;</a></div>';

} catch (Exception $e) {
    echo '<div class="err">Error critico: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '</body></html>';
