<?php
/* ============================================================
   WEBHOOKS de pasarelas (confirmación servidor-a-servidor)
   ------------------------------------------------------------
   Wompi:  configura la URL de eventos en tu panel apuntando a:
           <LS_BASE_URL>/api/webhooks.php?gateway=wompi
   PayPal: (opcional) webhook a:
           <LS_BASE_URL>/api/webhooks.php?gateway=paypal

   Esta vía es la MÁS CONFIABLE para activar licencias porque
   no depende de que el navegador del cliente regrese.
   ============================================================ */

require_once 'config.php';
require_once __DIR__ . '/gateways/wompi.php';
require_once __DIR__ . '/gateways/paypal.php';
require_once __DIR__ . '/mailer.php';

$pdo = lsGetConnection();
$gateway = strtolower($_GET['gateway'] ?? '');
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

if ($gateway === 'wompi') {
    // Verificar firma del evento
    if (!wompiVerifyWebhook($body)) {
        http_response_code(401);
        echo json_encode(['exito' => false, 'mensaje' => 'Firma de webhook inválida.']);
        exit;
    }
    $event = $body['event'] ?? '';
    $tx = $body['data']['transaction'] ?? [];
    if ($event === 'transaction.updated' && ($tx['status'] ?? '') === 'APPROVED') {
        $ref = $tx['reference'] ?? '';
        if ($ref) {
            $stmt = $pdo->prepare('SELECT p.*, l.duracion_meses FROM PAGOS p LEFT JOIN LICENCIAS l ON p.idLicencia = l.idLicencia WHERE p.idPago = ?');
            $stmt->execute([$ref]);
            $pago = $stmt->fetch();
            if ($pago && $pago['estado'] === 'PENDIENTE') {
                $meses = max(1, intval($pago['duracion_meses'] ?? 12));
                $pdo->prepare('UPDATE PAGOS SET estado="APROBADO", fecha_pago=NOW(), fecha_verificacion=NOW(), metodo="WOMPI", gateway_ref=?, notas="Aprobado por webhook Wompi" WHERE idPago=?')
                    ->execute([$tx['id'] ?? '', $ref]);
                if ($pago['idLicencia']) {
                    $pdo->prepare('UPDATE LICENCIAS SET estado="ACTIVA", fecha_activacion=NOW(), fecha_expiracion=DATE_ADD(NOW(), INTERVAL ? MONTH) WHERE idLicencia=? AND estado="PENDIENTE"')
                        ->execute([$meses, $pago['idLicencia']]);
                }
                lsLog($pdo, 'WEBHOOK_WOMPI', "Pago aprobado vía webhook: $ref", $pago['idLicencia'], null, $pago['idCliente']);
                @lsNotifyAdmin('✅ Pago Wompi aprobado (webhook) — ' . $ref, lsMailTable([
                    lsRow('Orden', $ref),
                    lsRow('Transacción', $tx['id'] ?? '—'),
                    lsRow('Estado', 'APPROVED'),
                ]));
            }
        }
    }
    http_response_code(200);
    echo json_encode(['exito' => true]);
    exit;
}

if ($gateway === 'paypal') {
    // Nota: PayPal recomienda verificar la firma del webhook.
    // Aquí registramos el evento; la activación principal ocurre
    // en payments.php (paypal_capture). Este webhook es respaldo.
    lsLog($pdo, 'WEBHOOK_PAYPAL', 'Evento recibido: ' . ($body['event_type'] ?? 'desconocido'));
    http_response_code(200);
    echo json_encode(['exito' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['exito' => false, 'mensaje' => 'Gateway no especificado.']);
