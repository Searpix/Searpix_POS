<?php
/* ============================================================
   Pasarela WOMPI — Helper
   Documentación: https://docs.wompi.co
   ------------------------------------------------------------
   Flujo (Web Checkout):
   1. El backend genera una firma de integridad y arma la URL
      de checkout de Wompi.
   2. El cliente es redirigido a Wompi para pagar.
   3. Wompi redirige de vuelta a redirect-url e (importante)
      envía un webhook al servidor.
   4. El backend consulta la transacción por su ID para
      confirmar el estado APPROVED antes de activar la licencia.
   ============================================================ */

/**
 * Firma de integridad SHA256 exigida por Wompi.
 * Cadena: "<referencia><amountInCents><currency><integritySecret>"
 */
function wompiIntegritySignature($referencia, $amountInCents, $currency = 'COP') {
    $cfg = wompiConfig();
    $cadena = $referencia . $amountInCents . $currency . $cfg['integrity'];
    return hash('sha256', $cadena);
}

/**
 * Construye los datos necesarios para lanzar el Web Checkout de Wompi
 * desde el frontend (redirección o widget).
 */
function wompiBuildCheckout($referencia, $montoCOP, $redirectUrl) {
    $cfg = wompiConfig();
    $amountInCents = intval(round($montoCOP * 100));
    return [
        'public_key'       => $cfg['public'],
        'currency'         => 'COP',
        'amount_in_cents'  => $amountInCents,
        'reference'        => $referencia,
        'signature'        => wompiIntegritySignature($referencia, $amountInCents, 'COP'),
        'redirect_url'     => $redirectUrl,
        'checkout_base'    => $cfg['checkout'],
    ];
}

/**
 * Consulta una transacción en Wompi por su ID para confirmar el estado.
 * Devuelve el objeto data de la transacción o null.
 */
function wompiGetTransaction($transactionId) {
    $cfg = wompiConfig();
    $url = $cfg['api'] . '/transactions/' . urlencode($transactionId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['private']],
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$res) return null;
    $json = json_decode($res, true);
    return $json['data'] ?? null;
}

/**
 * Busca una transacción por referencia (para el retorno del checkout).
 */
function wompiGetTransactionByReference($referencia) {
    $cfg = wompiConfig();
    $url = $cfg['api'] . '/transactions?reference=' . urlencode($referencia);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['private']],
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$res) return null;
    $json = json_decode($res, true);
    $data = $json['data'] ?? [];
    return !empty($data) ? $data[0] : null;
}

/**
 * Verifica la firma del webhook (evento) de Wompi.
 * Wompi envía signature.checksum = SHA256 de
 * (concat de properties + timestamp + events_secret).
 */
function wompiVerifyWebhook($body) {
    $cfg = wompiConfig();
    $signature = $body['signature'] ?? null;
    $timestamp = $body['timestamp'] ?? null;
    if (!$signature || !isset($signature['properties']) || !isset($signature['checksum'])) return false;

    $cadena = '';
    foreach ($signature['properties'] as $prop) {
        // prop ej: "transaction.id" -> navegar en data
        $val = $body['data'] ?? [];
        foreach (explode('.', $prop) as $key) {
            $val = $val[$key] ?? null;
        }
        $cadena .= $val;
    }
    $cadena .= $timestamp . $cfg['events'];
    $calc = hash('sha256', $cadena);
    return hash_equals(strtoupper($calc), strtoupper($signature['checksum']));
}
