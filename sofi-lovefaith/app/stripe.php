<?php
declare(strict_types=1);

/* Stripe Checkout via the REST API (no SDK needed on shared hosting). */

function stripe_mode(): string
{
    return setting('stripe_mode', 'test') === 'live' ? 'live' : 'test';
}

function stripe_secret(): string
{
    return trim((string)setting('stripe_sk_' . stripe_mode(), ''));
}

function stripe_publishable(): string
{
    return trim((string)setting('stripe_pk_' . stripe_mode(), ''));
}

function stripe_configured(): bool
{
    return setting_on('stripe_enabled', true) && str_starts_with(stripe_secret(), 'sk_');
}

/** Flatten nested arrays into Stripe's form encoding (a[b][0][c]=…). */
function stripe_encode(array $data, string $prefix = ''): array
{
    $out = [];
    foreach ($data as $k => $v) {
        $key = $prefix === '' ? (string)$k : $prefix . '[' . $k . ']';
        if (is_array($v)) $out += stripe_encode($v, $key);
        elseif (is_bool($v)) $out[$key] = $v ? 'true' : 'false';
        elseif ($v !== null) $out[$key] = (string)$v;
    }
    return $out;
}

function stripe_request(string $method, string $path, array $params = [], ?string $secret = null): array
{
    $secret = $secret ?? stripe_secret();
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/') . ($method === 'GET' && $params ? '?' . http_build_query(stripe_encode($params)) : ''));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $secret . ':',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20'],
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(stripe_encode($params)));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Stripe: ' . $err);
    $json = json_decode((string)$body, true) ?: [];
    if ($code >= 400) throw new RuntimeException('Stripe: ' . ($json['error']['message'] ?? ('HTTP ' . $code)));
    return $json;
}

function stripe_checkout(array $order, array $sum): string
{
    $currency = strtolower(setting('currency', 'USD'));
    $items = [];
    foreach ($sum['lines'] as $l) {
        $pd = ['name' => $l['name'] . ($l['variant_name'] ? ' — ' . $l['variant_name'] : '')];
        if ($l['image'] && !str_starts_with($l['image'], 'http')) $pd['images'] = [abs_url($l['image'])];
        $items[] = ['quantity' => $l['qty'], 'price_data' => ['currency' => $currency, 'unit_amount' => (int)round($l['price'] * 100), 'product_data' => $pd]];
    }
    $params = [
        'mode' => 'payment',
        'line_items' => $items,
        'client_reference_id' => $order['number'],
        'metadata' => ['order' => $order['number']],
        'locale' => lang() === 'es' ? 'es' : 'en',
        'success_url' => abs_url('/order/success') . '?n=' . rawurlencode($order['number']) . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => abs_url('/cart'),
        'phone_number_collection' => ['enabled' => true],
        'shipping_address_collection' => ['allowed_countries' => array_values(array_filter(array_map('trim', explode(',', (string)setting('ship_countries', 'US,MX')))))],
        'shipping_options' => [[
            'shipping_rate_data' => [
                'type' => 'fixed_amount',
                'display_name' => $sum['shipping'] > 0 ? (lang() === 'es' ? 'Envío estándar' : 'Standard shipping') : (lang() === 'es' ? 'Envío gratis' : 'Free shipping'),
                'fixed_amount' => ['amount' => (int)round($sum['shipping'] * 100), 'currency' => $currency],
            ],
        ]],
    ];
    if ($sum['discount'] > 0) {
        $coupon = stripe_request('POST', 'coupons', [
            'amount_off' => (int)round($sum['discount'] * 100), 'currency' => $currency,
            'duration' => 'once', 'name' => $order['coupon'] ?: 'Discount', 'max_redemptions' => 1,
        ]);
        $params['discounts'] = [['coupon' => $coupon['id']]];
    }
    $s = stripe_request('POST', 'checkout/sessions', $params);
    update('orders', ['stripe_session' => $s['id']], 'id = ?', [$order['id']]);
    return $s['url'];
}

/** Read customer/shipping data from a Checkout Session. */
function stripe_session_info(array $s): array
{
    $cd = $s['customer_details'] ?? [];
    $ship = $s['shipping_details'] ?? ($s['collected_information']['shipping_details'] ?? []);
    $a = $ship['address'] ?? ($cd['address'] ?? []);
    $addr = trim(implode("\n", array_filter([
        $ship['name'] ?? '', $a['line1'] ?? '', $a['line2'] ?? '',
        trim(($a['city'] ?? '') . ', ' . ($a['state'] ?? '') . ' ' . ($a['postal_code'] ?? ''), ', '), $a['country'] ?? '',
    ])));
    return ['email' => $cd['email'] ?? '', 'name' => $cd['name'] ?? ($ship['name'] ?? ''), 'phone' => $cd['phone'] ?? '', 'address' => $addr];
}

function stripe_verify_webhook(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool
{
    $parts = [];
    foreach (explode(',', $sigHeader) as $kv) {
        [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
        $parts[$k][] = $v;
    }
    $ts = (int)($parts['t'][0] ?? 0);
    if (!$ts || abs(time() - $ts) > $tolerance) return false;
    $expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
    foreach ($parts['v1'] ?? [] as $sig) if (hash_equals($expected, $sig)) return true;
    return false;
}
