<?php
/* ============================================================
   Pasarela PAYPAL — Helper (Orders API v2)
   Documentación: https://developer.paypal.com/docs/api/orders/v2
   ------------------------------------------------------------
   Flujo:
   1. Backend obtiene un access_token (OAuth2 client_credentials).
   2. Backend crea una Order (intent=CAPTURE) y devuelve su ID.
   3. El frontend (PayPal Buttons) aprueba el pago.
   4. Backend hace CAPTURE de la Order y confirma COMPLETED
      antes de activar la licencia.
   ============================================================ */

/**
 * Obtiene un access token OAuth2 de PayPal.
 */
function paypalAccessToken() {
    $cfg = paypalConfig();
    $ch = curl_init($cfg['api'] . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => $cfg['client_id'] . ':' . $cfg['secret'],
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$res) return null;
    $json = json_decode($res, true);
    return $json['access_token'] ?? null;
}

/**
 * Convierte COP a USD (PayPal no opera en COP).
 */
function paypalCopToUsd($montoCOP) {
    $usd = $montoCOP / PAYPAL_COP_TO_USD;
    return number_format($usd, 2, '.', '');
}

/**
 * Crea una Order de PayPal (intent CAPTURE). Devuelve [id, ...] o null.
 */
function paypalCreateOrder($referencia, $montoCOP, $descripcion, $returnUrl, $cancelUrl) {
    $cfg = paypalConfig();
    $token = paypalAccessToken();
    if (!$token) return null;

    $usd = paypalCopToUsd($montoCOP);
    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $referencia,
            'description'  => substr($descripcion, 0, 120),
            'amount' => [
                'currency_code' => 'USD',
                'value' => $usd,
            ],
        ]],
        'application_context' => [
            'brand_name'  => 'Comandix',
            'user_action' => 'PAY_NOW',
            'return_url'  => $returnUrl,
            'cancel_url'  => $cancelUrl,
        ],
    ];

    $ch = curl_init($cfg['api'] . '/v2/checkout/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (($code !== 201 && $code !== 200) || !$res) return null;
    return json_decode($res, true);
}

/**
 * Captura una Order previamente aprobada por el cliente.
 * Devuelve el objeto de captura o null.
 */
function paypalCaptureOrder($orderId) {
    $cfg = paypalConfig();
    $token = paypalAccessToken();
    if (!$token) return null;

    $ch = curl_init($cfg['api'] . '/v2/checkout/orders/' . urlencode($orderId) . '/capture');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (($code !== 201 && $code !== 200) || !$res) return null;
    return json_decode($res, true);
}
