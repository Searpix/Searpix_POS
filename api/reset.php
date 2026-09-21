<?php
/* ============================================================
   Comandix Licencias — Confirmación de reinicio de licencia (2FA)
   ------------------------------------------------------------
   Se accede desde el enlace enviado por correo al cliente.
   Regenera la CLAVE de activación SIN tocar la fecha de
   activación ni la de expiración, y cierra los dispositivos
   que estaban usando la licencia (por si alguien más tuvo
   acceso). El token es de un solo uso y caduca a los 30 min.
   ============================================================ */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

// Esta pantalla es HTML para el navegador (config.php fija JSON por defecto).
header('Content-Type: text/html; charset=utf-8');

/**
 * Renderiza una página de resultado con la identidad visual de Comandix.
 * $estado: 'ok' | 'error'
 */
function resetPage($estado, $titulo, $cuerpo) {
    $ok    = $estado === 'ok';
    $color = $ok ? '#4ADE80' : '#E94560';
    $glow  = $ok ? 'rgba(74,222,128,.55)' : 'rgba(233,69,96,.55)';
    // Icono animado (SVG, sin JS)
    if ($ok) {
        $mark = '<svg viewBox="0 0 52 52" class="mark"><circle class="mark-ring" cx="26" cy="26" r="24" fill="none"/>'
              . '<path class="mark-check" fill="none" d="M14 27 L23 35 L39 18"/></svg>';
    } else {
        $mark = '<svg viewBox="0 0 52 52" class="mark"><circle class="mark-ring" cx="26" cy="26" r="24" fill="none"/>'
              . '<line class="mark-check" x1="26" y1="15" x2="26" y2="31"/>'
              . '<line class="mark-check dot" x1="26" y1="38" x2="26" y2="38"/></svg>';
    }
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($titulo) . ' — Comandix</title><style>'
        . ':root{--c:' . $color . ';--glow:' . $glow . '}'
        . '*{box-sizing:border-box;margin:0;padding:0}'
        . 'body{font-family:"Segoe UI",Arial,Helvetica,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;color:#e2e8f0;position:relative;overflow:hidden;background:#0b0b16}'
        . '.bg{position:fixed;inset:0;z-index:0;overflow:hidden}'
        . '.blob{position:absolute;border-radius:50%;filter:blur(70px);opacity:.5;animation:float 14s ease-in-out infinite}'
        . '.blob.b1{width:420px;height:420px;background:#6C3CE1;top:-120px;left:-100px}'
        . '.blob.b2{width:380px;height:380px;background:#E94560;bottom:-140px;right:-90px;animation-delay:-5s}'
        . '.blob.b3{width:260px;height:260px;background:#38BDF8;top:40%;left:55%;opacity:.28;animation-delay:-9s}'
        . '@keyframes float{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(20px,-30px) scale(1.08)}}'
        . '.card{position:relative;z-index:1;max-width:500px;width:100%;background:rgba(26,26,46,.72);backdrop-filter:blur(18px);'
        . 'border:1px solid rgba(255,255,255,.1);border-radius:24px;overflow:hidden;box-shadow:0 40px 100px -30px rgba(0,0,0,.8);animation:rise .6s cubic-bezier(.2,.8,.25,1) both}'
        . '@keyframes rise{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none}}'
        . '.head{background:linear-gradient(135deg,#6C3CE1,#E94560);padding:20px 28px;display:flex;align-items:center;gap:12px}'
        . '.head img{height:32px;width:auto;filter:drop-shadow(0 2px 6px rgba(0,0,0,.3))}'
        . '.head h1{font-size:17px;color:#fff;font-weight:800;letter-spacing:.3px}'
        . '.body{padding:34px 30px 30px;text-align:center}'
        . '.badge{width:100px;height:100px;margin:0 auto 22px;border-radius:50%;display:flex;align-items:center;justify-content:center;'
        . 'background:radial-gradient(circle,rgba(255,255,255,.06),transparent 70%);position:relative}'
        . '.badge::after{content:"";position:absolute;inset:0;border-radius:50%;box-shadow:0 0 0 0 var(--glow);animation:pulse 2s ease-out infinite}'
        . '@keyframes pulse{0%{box-shadow:0 0 0 0 var(--glow)}70%{box-shadow:0 0 0 22px transparent}100%{box-shadow:0 0 0 0 transparent}}'
        . '.mark{width:100px;height:100px}'
        . '.mark-ring{stroke:var(--c);stroke-width:3;stroke-dasharray:151;stroke-dashoffset:151;animation:draw .6s ease forwards}'
        . '.mark-check{stroke:var(--c);stroke-width:4;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:60;stroke-dashoffset:60;animation:draw .45s .5s ease forwards}'
        . '.mark-check.dot{stroke-dasharray:2;stroke-dashoffset:2;animation:draw .2s .95s ease forwards}'
        . '@keyframes draw{to{stroke-dashoffset:0}}'
        . 'h2{font-size:22px;color:#fff;margin-bottom:12px;font-weight:800}'
        . 'p{font-size:14.5px;line-height:1.7;color:#cbd5e1;margin-bottom:12px}'
        . 'p strong{color:#fff}'
        . '.key{font-family:Consolas,"Courier New",monospace;font-weight:800;letter-spacing:2px;font-size:22px;color:#fff;'
        . 'background:linear-gradient(135deg,rgba(108,60,225,.22),rgba(233,69,96,.18));border:1px dashed rgba(255,255,255,.35);'
        . 'border-radius:14px;padding:18px 14px;text-align:center;margin:20px 0;word-break:break-all;animation:rise .5s .6s both}'
        . '.info{display:flex;gap:10px;text-align:left;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);'
        . 'border-radius:14px;padding:14px 16px;margin:18px 0}'
        . '.info ul{margin:0;padding-left:18px;font-size:13px;color:#cbd5e1;line-height:1.7}'
        . '.muted{font-size:12px;color:#94A3B8;margin-top:18px;line-height:1.6}'
        . '.foot{border-top:1px solid rgba(255,255,255,.07);padding:16px 28px;text-align:center;font-size:11.5px;color:#7b849b}'
        . '</style></head><body>'
        . '<div class="bg"><span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span></div>'
        . '<div class="card">'
        . '<div class="head"><img src="../assets/img/logo.png" alt="Comandix"><h1>Comandix · Licencias</h1></div>'
        . '<div class="body"><div class="badge">' . $mark . '</div>'
        . '<h2>' . htmlspecialchars($titulo) . '</h2>' . $cuerpo . '</div>'
        . '<div class="foot">Comandix — POS para restaurantes, por Searpix</div>'
        . '</div></body></html>';
    exit;
}

$token = trim($_GET['token'] ?? '');
if ($token === '' || !ctype_xdigit($token) || strlen($token) < 32) {
    resetPage('error', 'Enlace no válido', '<p>El enlace de confirmación es inválido o está incompleto. Vuelve a solicitar el reinicio desde tu portal.</p>');
}

try {
    $pdo = lsGetConnection();
    $tokenHash = lsHashToken('reset:' . $token);

    $stmt = $pdo->prepare('SELECT * FROM LICENCIA_RESETS WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$tokenHash]);
    $req = $stmt->fetch();

    if (!$req) {
        resetPage('error', 'Enlace no válido', '<p>No encontramos esta solicitud. Es posible que el enlace ya haya sido utilizado. Solicita un nuevo reinicio desde tu portal.</p>');
    }
    if ($req['estado'] === 'CONFIRMADO') {
        resetPage('error', 'Enlace ya utilizado', '<p>Esta solicitud de reinicio ya fue confirmada anteriormente. Si necesitas resetear de nuevo, genera una nueva solicitud desde tu portal.</p>');
    }
    if ($req['estado'] === 'CANCELADO') {
        resetPage('error', 'Enlace cancelado', '<p>Esta solicitud fue reemplazada por una más reciente o cancelada. Usa el enlace del correo más reciente o solicita uno nuevo.</p>');
    }
    if (strtotime($req['expira']) < time()) {
        $pdo->prepare("UPDATE LICENCIA_RESETS SET estado = 'CANCELADO' WHERE id = ?")->execute([$req['id']]);
        resetPage('error', 'Enlace expirado', '<p>El enlace de confirmación ha expirado por seguridad. Solicita un nuevo reinicio desde tu portal.</p>');
    }

    // La licencia debe seguir existiendo
    $ls = $pdo->prepare('SELECT * FROM LICENCIAS WHERE idLicencia = ? LIMIT 1');
    $ls->execute([$req['idLicencia']]);
    $lic = $ls->fetch();
    if (!$lic) {
        resetPage('error', 'Licencia no encontrada', '<p>No pudimos localizar la licencia asociada. Contacta a soporte.</p>');
    }

    $nuevaClave = lsGenerarClave();

    $pdo->beginTransaction();
    // Solo cambia la CLAVE y se reinician los dispositivos. Las fechas quedan intactas.
    $pdo->prepare('UPDATE LICENCIAS SET clave_activacion = ?, activaciones = 0, hardware_id = NULL WHERE idLicencia = ?')
        ->execute([$nuevaClave, $req['idLicencia']]);
    // Cerrar los dispositivos que usaban la licencia (tabla opcional)
    try {
        $pdo->prepare("UPDATE LICENCIA_ACTIVACIONES SET estado = 'REVOCADA' WHERE idLicencia = ?")->execute([$req['idLicencia']]);
    } catch (Exception $e) { /* la tabla puede no existir aún */ }
    // Marcar la solicitud como confirmada (un solo uso)
    $pdo->prepare("UPDATE LICENCIA_RESETS SET estado = 'CONFIRMADO', clave_nueva = ?, confirmado = NOW() WHERE id = ?")
        ->execute([$nuevaClave, $req['id']]);
    $pdo->commit();

    lsLog($pdo, 'LICENSE_RESET', 'Licencia reseteada por el cliente (2FA email). Nueva clave emitida.', $req['idLicencia'], null, $req['idCliente']);

    // Correo informativo con la nueva clave
    $cs = $pdo->prepare('SELECT email, nombre FROM CLIENTES WHERE idCliente = ? LIMIT 1');
    $cs->execute([$req['idCliente']]);
    $cli = $cs->fetch();
    if ($cli && filter_var($cli['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $htmlOk = '<h2 style="color:#fff;margin:0 0 12px;font-size:18px">Tu licencia fue reseteada</h2>'
            . '<p style="color:#cbd5e1;font-size:14px;line-height:1.6">Hola ' . htmlspecialchars($cli['nombre'] ?: 'Cliente') . ', tu nueva clave de activación es:</p>'
            . '<div style="font-family:monospace;font-weight:700;letter-spacing:1px;font-size:20px;color:#fff;text-align:center;background:rgba(108,60,225,.14);border:1px dashed rgba(108,60,225,.5);border-radius:12px;padding:16px;margin:14px 0">' . htmlspecialchars($nuevaClave) . '</div>'
            . '<p style="color:#94A3B8;font-size:12px;line-height:1.6">La clave anterior quedó inhabilitada y los dispositivos vinculados fueron cerrados. Tu fecha de activación y expiración no cambiaron.</p>';
        lsSendMail($cli['email'], 'Licencia reseteada — nueva clave de activación', $htmlOk);
    }

    $cuerpo = '<p>El reinicio se completó correctamente. Tu <strong>nueva clave de activación</strong> es:</p>'
        . '<div class="key">' . htmlspecialchars($nuevaClave) . '</div>'
        . '<p>La clave anterior quedó inhabilitada y los dispositivos que la usaban fueron cerrados. Tu <strong>fecha de activación y expiración se mantienen sin cambios</strong>.</p>'
        . '<p class="muted">También enviamos esta clave a tu correo. Ya puedes cerrar esta ventana.</p>';
    resetPage('ok', 'Licencia reseteada', $cuerpo);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[Comandix][reset] ' . $e->getMessage());
    resetPage('error', 'Error inesperado', '<p>Ocurrió un problema al procesar el reinicio. Intenta nuevamente desde el enlace del correo o contacta a soporte.</p>');
}
