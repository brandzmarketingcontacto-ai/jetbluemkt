<?php
// Pasarelas de pago: Stripe Checkout y PayPal Orders v2 (pago con tarjeta).
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';

function http_request(string $method, string $url, array $headers = [], $body = null, ?string $basicAuth = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($basicAuth !== null) curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('Error de conexión con la pasarela: ' . $err);
    return [$code, json_decode($res, true) ?: []];
}

// ---------- Stripe ----------
function stripe_create_session(array $order, array $lines): string {
    $sk = s('stripe_sk');
    if ($sk === '') throw new RuntimeException('Stripe no está configurado.');
    $cur = strtolower(s('currency', 'USD'));
    $data = [
        'mode' => 'payment',
        'payment_method_types' => ['card'],
        'customer_email' => $order['email'],
        'client_reference_id' => $order['code'],
        'metadata' => ['order' => $order['code']],
        'success_url' => abs_url('pago/stripe?session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url' => abs_url('carrito?cancelado=1'),
        'line_items' => [],
    ];
    foreach ($lines as $l) {
        $data['line_items'][] = ['quantity' => $l['qty'], 'price_data' => [
            'currency' => $cur, 'unit_amount' => (int)round($l['price'] * 100),
            'product_data' => ['name' => $l['title'] . ' — ' . $l['edition']],
        ]];
    }
    if ($order['shipping'] > 0) {
        $data['line_items'][] = ['quantity' => 1, 'price_data' => [
            'currency' => $cur, 'unit_amount' => (int)round($order['shipping'] * 100), 'product_data' => ['name' => 'Envío'],
        ]];
    }
    [$code, $res] = http_request('POST', 'https://api.stripe.com/v1/checkout/sessions',
        ['Content-Type: application/x-www-form-urlencoded'], http_build_query($data), $sk . ':');
    if ($code >= 300 || empty($res['url'])) throw new RuntimeException('Stripe: ' . ($res['error']['message'] ?? 'error ' . $code));
    db()->prepare('UPDATE orders SET gateway_ref = ? WHERE code = ?')->execute([$res['id'], $order['code']]);
    return $res['url'];
}

function stripe_verify_session(string $sessionId): ?string {
    [$code, $res] = http_request('GET', 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId), [], null, s('stripe_sk') . ':');
    if ($code === 200 && ($res['payment_status'] ?? '') === 'paid') {
        $orderCode = $res['client_reference_id'] ?? '';
        mark_order_paid($orderCode, $sessionId);
        return $orderCode;
    }
    return null;
}

function stripe_webhook(): void {
    $payload = file_get_contents('php://input');
    $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $secret = s('stripe_whsec');
    if ($secret === '') { http_response_code(400); exit('sin secreto'); }
    $parts = [];
    foreach (explode(',', $sig) as $p) { [$k, $v] = array_pad(explode('=', $p, 2), 2, ''); $parts[$k][] = $v; }
    $t = $parts['t'][0] ?? '';
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    $valid = false;
    foreach ($parts['v1'] ?? [] as $v1) if (hash_equals($expected, $v1)) $valid = true;
    if (!$valid || abs(time() - (int)$t) > 600) { http_response_code(400); exit('firma inválida'); }
    $event = json_decode($payload, true);
    if (($event['type'] ?? '') === 'checkout.session.completed') {
        $obj = $event['data']['object'];
        if (($obj['payment_status'] ?? '') === 'paid') mark_order_paid($obj['client_reference_id'] ?? '', $obj['id']);
    }
    http_response_code(200);
    echo 'ok';
}

// ---------- PayPal ----------
function paypal_base(): string {
    return s('paypal_mode') === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
}

function paypal_token(): string {
    if (s('paypal_client') === '' || s('paypal_secret') === '') throw new RuntimeException('PayPal no está configurado.');
    [$code, $res] = http_request('POST', paypal_base() . '/v1/oauth2/token',
        ['Content-Type: application/x-www-form-urlencoded'], 'grant_type=client_credentials', s('paypal_client') . ':' . s('paypal_secret'));
    if ($code !== 200 || empty($res['access_token'])) throw new RuntimeException('PayPal: no se pudo autenticar.');
    return $res['access_token'];
}

function paypal_create_order(array $order, array $lines): string {
    $tok = paypal_token();
    $cur = s('currency', 'USD');
    $items = [];
    foreach ($lines as $l) {
        $items[] = ['name' => mb_substr($l['title'] . ' — ' . $l['edition'], 0, 120), 'quantity' => (string)$l['qty'],
            'unit_amount' => ['currency_code' => $cur, 'value' => number_format($l['price'], 2, '.', '')]];
    }
    $body = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $order['code'],
            'custom_id' => $order['code'],
            'amount' => ['currency_code' => $cur, 'value' => number_format($order['total'], 2, '.', ''),
                'breakdown' => [
                    'item_total' => ['currency_code' => $cur, 'value' => number_format($order['subtotal'], 2, '.', '')],
                    'shipping' => ['currency_code' => $cur, 'value' => number_format($order['shipping'], 2, '.', '')],
                ]],
            'items' => $items,
        ]],
        'payment_source' => ['paypal' => ['experience_context' => [
            'landing_page' => 'GUEST_CHECKOUT',
            'user_action' => 'PAY_NOW',
            'shipping_preference' => 'NO_SHIPPING',
            'brand_name' => mb_substr(s('site_name'), 0, 120),
            'return_url' => abs_url('pago/paypal'),
            'cancel_url' => abs_url('carrito?cancelado=1'),
        ]]],
    ];
    [$code, $res] = http_request('POST', paypal_base() . '/v2/checkout/orders',
        ['Content-Type: application/json', 'Authorization: Bearer ' . $tok], json_encode($body));
    if ($code >= 300) throw new RuntimeException('PayPal: ' . ($res['message'] ?? 'error ' . $code));
    db()->prepare('UPDATE orders SET gateway_ref = ? WHERE code = ?')->execute([$res['id'], $order['code']]);
    foreach ($res['links'] ?? [] as $l) if (in_array($l['rel'], ['payer-action', 'approve'], true)) return $l['href'];
    throw new RuntimeException('PayPal no devolvió enlace de pago.');
}

function paypal_capture(string $ppOrderId): ?string {
    $tok = paypal_token();
    [$code, $res] = http_request('POST', paypal_base() . '/v2/checkout/orders/' . rawurlencode($ppOrderId) . '/capture',
        ['Content-Type: application/json', 'Authorization: Bearer ' . $tok], '{}');
    if (in_array($code, [200, 201], true) && ($res['status'] ?? '') === 'COMPLETED') {
        $st = db()->prepare('SELECT code FROM orders WHERE gateway_ref = ?');
        $st->execute([$ppOrderId]);
        $orderCode = (string)$st->fetchColumn();
        mark_order_paid($orderCode, $ppOrderId);
        return $orderCode;
    }
    return null;
}

// ---------- Pedidos ----------
function mark_order_paid(string $code, string $ref): void {
    if ($code === '') return;
    $st = db()->prepare("UPDATE orders SET status = 'pagado', gateway_ref = ? WHERE code = ? AND status = 'pendiente'");
    $st->execute([$ref, $code]);
    if ($st->rowCount() === 0) return; // ya estaba pagado: no repetir correos
    $o = db()->prepare('SELECT * FROM orders WHERE code = ?');
    $o->execute([$code]);
    $order = $o->fetch();
    if (!$order) return;
    $rows = '';
    foreach (json_decode($order['items'], true) as $l) {
        $rows .= '<tr><td style="padding:6px 0">' . e($l['title']) . ' — ' . e($l['edition']) . ' × ' . (int)$l['qty'] . '</td><td style="text-align:right">' . money($l['total']) . '</td></tr>';
    }
    $table = '<table style="width:100%;border-collapse:collapse">' . $rows
        . '<tr><td style="padding-top:10px">Envío</td><td style="text-align:right;padding-top:10px">' . money($order['shipping']) . '</td></tr>'
        . '<tr><td><b>Total</b></td><td style="text-align:right"><b>' . money($order['total']) . '</b></td></tr></table>';
    $info = '<p><b>Pedido:</b> ' . e($order['code']) . '<br><b>Cliente:</b> ' . e($order['name']) . ' (' . e($order['email']) . ')<br><b>Teléfono:</b> ' . e($order['phone'])
        . '<br><b>Dirección:</b> ' . nl2br(e($order['address'])) . ($order['dedication'] ? '<br><b>Dedicatoria:</b> ' . nl2br(e($order['dedication'])) : '') . '</p>';
    notify_admin('Nueva venta ' . $order['code'] . ' · ' . money($order['total']), $info . $table, $order['email']);
    send_mail($order['email'], 'Gracias por tu compra · Pedido ' . $order['code'],
        email_template('¡Gracias por tu compra, ' . $order['name'] . '!', '<p>Recibimos tu pago. Te avisaremos cuando tu pedido salga.</p>' . $table));
}
