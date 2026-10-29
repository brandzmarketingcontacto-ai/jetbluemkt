<?php
declare(strict_types=1);

/* ---------- Products ---------- */

function product_media(int $productId): array
{
    return rows('SELECT * FROM product_media WHERE product_id = ? ORDER BY sort, id', [$productId]);
}

/** First image-like media for cards (video posters fall back to the next image). */
function product_cover(array $p): string
{
    static $cache = [];
    $id = (int)$p['id'];
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = '';
        foreach (product_media($id) as $m) {
            if ($m['kind'] === 'image' || $m['kind'] === 'gif') { $cache[$id] = $m['path']; break; }
            if (!empty($m['poster'])) { $cache[$id] = $m['poster']; break; }
        }
    }
    return $cache[$id];
}

function product_url(array $p): string
{
    return url('/product/' . $p['slug']);
}

function product_on_sale(array $p): bool
{
    return $p['compare_price'] !== null && (float)$p['compare_price'] > (float)$p['price'];
}

function product_badge(array $p): string
{
    $b = tr($p, 'badge');
    if ($b !== '') return $b;
    $pct = discount_pct($p['price'], $p['compare_price']);
    return $pct > 0 ? '-' . $pct . '%' : '';
}

function product_category_names(array $p): string
{
    static $cats = null;
    if ($cats === null) {
        $cats = [];
        foreach (rows('SELECT * FROM categories') as $c) $cats[$c['slug']] = $c;
    }
    $names = [];
    foreach (array_filter(explode(',', (string)$p['categories'])) as $slug) {
        if (isset($cats[$slug])) $names[] = tr($cats[$slug], 'name');
    }
    $label = implode(' · ', array_slice($names, 0, 1));
    if ($p['type'] !== '') $label .= ($label ? ' · ' : '') . $p['type'];
    return $label;
}

function products_list(array $o = []): array
{
    $where = ['status = "published"'];
    $params = [];
    if (!empty($o['cat'])) { $where[] = 'FIND_IN_SET(?, categories)'; $params[] = $o['cat']; }
    if (!empty($o['sale'])) $where[] = 'compare_price IS NOT NULL AND compare_price > price';
    if (!empty($o['q'])) {
        $where[] = '(name_en LIKE ? OR name_es LIKE ? OR tags LIKE ? OR material LIKE ? OR type LIKE ?)';
        $like = '%' . $o['q'] . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if (!empty($o['max'])) { $where[] = 'price <= ?'; $params[] = (float)$o['max']; }
    if (!empty($o['exclude'])) { $where[] = 'id <> ?'; $params[] = (int)$o['exclude']; }
    if (!empty($o['ids'])) {
        $ids = array_map('intval', $o['ids']) ?: [0];
        $where[] = 'id IN (' . implode(',', $ids) . ')';
    }
    $order = match ($o['sort'] ?? '') {
        'new' => 'created_at DESC, id DESC',
        'low' => 'price ASC',
        'high' => 'price DESC',
        'disc' => '(COALESCE(compare_price, price) - price) / GREATEST(COALESCE(compare_price, price), 0.01) DESC',
        'random' => 'RAND()',
        default => 'featured DESC, sort ASC, id DESC',
    };
    $sql = 'SELECT * FROM products WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order;
    if (!empty($o['limit'])) $sql .= ' LIMIT ' . (int)$o['limit'] . (isset($o['offset']) ? ' OFFSET ' . (int)$o['offset'] : '');
    return rows($sql, $params);
}

function product_card(array $p): string
{
    $cover = product_cover($p);
    $badge = product_badge($p);
    $name = tr($p, 'name');
    $h = '<article class="p-card zoom">';
    $h .= '<a class="p-img" href="' . e(product_url($p)) . '">';
    $h .= $cover ? '<img src="' . e(media_url($cover)) . '" alt="' . e($name) . '" loading="lazy">' : '<span class="p-ph">' . icon('gift', 40) . '</span>';
    if ($badge !== '') $h .= '<span class="p-badge">' . e($badge) . '</span>';
    if ((int)$p['track_stock'] && (int)$p['stock'] <= 0 && !(int)$p['allow_backorder']) $h .= '<span class="p-badge soldout">' . e(t('out_of_stock')) . '</span>';
    $h .= '</a>';
    $h .= '<span class="p-cat">' . e(product_category_names($p)) . '</span>';
    $h .= '<a class="p-name" href="' . e(product_url($p)) . '">' . e($name) . '</a>';
    $h .= '<span class="p-price"><span>' . e(money($p['price'])) . '</span>';
    if (product_on_sale($p)) $h .= '<s>' . e(money($p['compare_price'])) . '</s>';
    $h .= '</span></article>';
    return $h;
}

function product_variants(array $p): array
{
    return jdec($p['variants'] ?? '', []);
}

function find_variant(array $p, string $key): ?array
{
    foreach (product_variants($p) as $v) if (($v['key'] ?? '') === $key) return $v;
    return null;
}

/** Variant name in the visitor's language, e.g. "Dorado / 18\"". */
function variant_label(array $p, ?array $v): string
{
    if (!$v) return '';
    $opts = jdec($p['options'] ?? '', []);
    $parts = [];
    foreach (explode('-', (string)($v['key'] ?? '')) as $oi => $vi) {
        $val = $opts[$oi]['values'][(int)$vi] ?? null;
        if ($val) $parts[] = $val[lang()] ?? ($val['en'] ?? '');
    }
    return $parts ? implode(' / ', array_filter($parts)) : (string)($v['name'] ?? '');
}

/** Effective stock for a product/variant. */
function available_stock(array $p, ?array $variant): int
{
    if (!(int)$p['track_stock'] || (int)$p['allow_backorder']) return 9999;
    if ($variant && isset($variant['stock']) && $variant['stock'] !== '') return (int)$variant['stock'];
    return (int)$p['stock'];
}

function product_rating(int $productId): array
{
    $r = row('SELECT COUNT(*) n, AVG(rating) a FROM reviews WHERE product_id = ? AND status = "approved"', [$productId]);
    return ['count' => (int)($r['n'] ?? 0), 'avg' => $r['a'] ? round((float)$r['a'], 1) : 0];
}

/* ---------- Cart (session) ---------- */

function cart(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_count(): int
{
    return array_sum(array_column(cart(), 'qty'));
}

function cart_add(int $pid, string $variant, int $qty): bool
{
    $p = row('SELECT * FROM products WHERE id = ? AND status = "published"', [$pid]);
    if (!$p) return false;
    $v = $variant !== '' ? find_variant($p, $variant) : null;
    if ($variant !== '' && !$v) return false;
    $key = $pid . ':' . $variant;
    $cart = cart();
    $current = $cart[$key]['qty'] ?? 0;
    $max = available_stock($p, $v);
    $new = min($max, $current + max(1, $qty));
    if ($new <= 0) return false;
    $cart[$key] = ['pid' => $pid, 'variant' => $variant, 'qty' => $new];
    $_SESSION['cart'] = $cart;
    return true;
}

function cart_set(string $key, int $qty): void
{
    $cart = cart();
    if (!isset($cart[$key])) return;
    if ($qty <= 0) unset($cart[$key]);
    else $cart[$key]['qty'] = min(99, $qty);
    $_SESSION['cart'] = $cart;
}

/** Resolve the cart against the database: lines with prices, totals, coupon. */
function cart_summary(): array
{
    $lines = [];
    $subtotal = 0.0;
    foreach (cart() as $key => $item) {
        $p = row('SELECT * FROM products WHERE id = ? AND status = "published"', [$item['pid']]);
        if (!$p) continue;
        $v = $item['variant'] !== '' ? find_variant($p, $item['variant']) : null;
        $price = ($v && isset($v['price']) && $v['price'] !== '') ? (float)$v['price'] : (float)$p['price'];
        $qty = min((int)$item['qty'], available_stock($p, $v));
        if ($qty <= 0) continue;
        $lines[] = [
            'key' => $key, 'product' => $p, 'variant' => $v, 'qty' => $qty, 'price' => $price,
            'total' => $price * $qty, 'name' => tr($p, 'name'), 'variant_name' => variant_label($p, $v),
            'image' => ($v['image'] ?? '') ?: product_cover($p),
        ];
        $subtotal += $price * $qty;
    }
    $coupon = null;
    $discount = 0.0;
    if (!empty($_SESSION['coupon'])) {
        $coupon = coupon_find($_SESSION['coupon']);
        if ($coupon) $discount = coupon_discount($coupon, $lines, $subtotal);
        if (!$coupon || $discount <= 0) { $coupon = null; $discount = 0.0; }
    }
    // "Complete the look" bundle: % off one unit of each bundled product while all are in the cart
    $bundle = $_SESSION['bundle'] ?? null;
    if ($bundle && !empty($bundle['pct'])) {
        $inCart = [];
        foreach ($lines as $l) $inCart[(int)$l['product']['id']] = $l['price'];
        if (!array_diff($bundle['ids'], array_keys($inCart))) {
            $bd = 0.0;
            foreach ($bundle['ids'] as $id) $bd += $inCart[$id] * $bundle['pct'] / 100;
            $discount += round($bd, 2);
        }
    }
    $after = max(0, $subtotal - $discount);
    $freeFrom = (float)setting('free_shipping_from', '60');
    $flat = (float)setting('shipping_flat', '6.99');
    $shipping = ($after <= 0 || ($freeFrom > 0 && $after >= $freeFrom)) ? 0.0 : $flat;
    return [
        'lines' => $lines, 'subtotal' => round($subtotal, 2), 'discount' => round($discount, 2),
        'coupon' => $coupon, 'shipping' => round($shipping, 2), 'total' => round($after + $shipping, 2),
        'free_from' => $freeFrom, 'missing_free' => max(0, round($freeFrom - $after, 2)),
    ];
}

function coupon_find(string $code): ?array
{
    $c = row('SELECT * FROM coupons WHERE code = ? AND active = 1', [strtoupper(trim($code))]);
    if (!$c) return null;
    if ($c['expires_at'] && strtotime($c['expires_at']) < time()) return null;
    return $c;
}

function coupon_discount(array $c, array $lines, float $subtotal): float
{
    if ($subtotal < (float)$c['min_total']) return 0.0;
    $base = $subtotal;
    if ($c['category'] !== '') {
        $base = 0.0;
        foreach ($lines as $l) {
            if (in_array($c['category'], explode(',', (string)$l['product']['categories']), true)) $base += $l['total'];
        }
    }
    if ($base <= 0) return 0.0;
    $d = $c['type'] === 'fixed' ? (float)$c['value'] : $base * ((float)$c['value'] / 100);
    return min($d, $base);
}

/* ---------- Orders ---------- */

function order_create(array $sum, string $giftMessage = ''): array
{
    $items = [];
    foreach ($sum['lines'] as $l) {
        $items[] = [
            'pid' => (int)$l['product']['id'], 'sku' => $l['variant']['sku'] ?? $l['product']['sku'],
            'name' => $l['name'], 'variant' => $l['variant_name'], 'variant_key' => $l['variant']['key'] ?? '',
            'qty' => $l['qty'], 'price' => $l['price'], 'image' => $l['image'],
        ];
    }
    $number = 'SLF-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $id = insert('orders', [
        'number' => $number, 'status' => 'pending', 'items' => jenc($items),
        'gift_message' => mb_substr($giftMessage, 0, 500),
        'subtotal' => $sum['subtotal'], 'discount' => $sum['discount'], 'shipping' => $sum['shipping'], 'total' => $sum['total'],
        'currency' => setting('currency', 'USD'), 'coupon' => $sum['coupon']['code'] ?? '',
        'lang' => lang(), 'created_at' => date('Y-m-d H:i:s'),
    ]);
    return row('SELECT * FROM orders WHERE id = ?', [$id]);
}

/** Mark an order as paid (idempotent): stock, coupon usage and notifications. */
function order_mark_paid(array $order, array $info = []): void
{
    if ($order['status'] !== 'pending') return;
    $data = array_filter([
        'status' => 'paid', 'paid_at' => date('Y-m-d H:i:s'),
        'email' => $info['email'] ?? null, 'name' => $info['name'] ?? null,
        'phone' => $info['phone'] ?? null, 'address' => $info['address'] ?? null,
    ], fn($v) => $v !== null);
    $set = implode(',', array_map(fn($c) => '`' . $c . '`=?', array_keys($data)));
    $st = q('UPDATE orders SET ' . $set . ' WHERE id = ? AND status = "pending"', array_merge(array_values($data), [$order['id']]));
    if ($st->rowCount() === 0) return; // another request (webhook / success page) already processed it

    $lowStock = [];
    foreach (jdec($order['items'], []) as $it) {
        $p = row('SELECT * FROM products WHERE id = ?', [$it['pid']]);
        if (!$p || !(int)$p['track_stock']) continue;
        if (!empty($it['variant_key'])) {
            $vars = product_variants($p);
            foreach ($vars as &$v) {
                if (($v['key'] ?? '') === $it['variant_key'] && isset($v['stock']) && $v['stock'] !== '') {
                    $v['stock'] = max(0, (int)$v['stock'] - (int)$it['qty']);
                }
            }
            unset($v);
            q('UPDATE products SET variants = ? WHERE id = ?', [jenc($vars), $p['id']]);
        }
        q('UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?', [(int)$it['qty'], $p['id']]);
        $left = (int)val('SELECT stock FROM products WHERE id = ?', [$p['id']]);
        if ($left <= (int)setting('low_stock_at', '5')) $lowStock[] = $p['name_en'] . ' (' . $left . ')';
    }
    if ($order['coupon'] !== '') q('UPDATE coupons SET uses = uses + 1 WHERE code = ?', [$order['coupon']]);

    $order = row('SELECT * FROM orders WHERE id = ?', [$order['id']]);
    notify_order($order);
    if ($lowStock && setting_on('notify_stock', true)) {
        notify_admin('Stock bajo: ' . implode(', ', $lowStock), '<p>Estos productos tienen poco inventario:</p><ul><li>' . implode('</li><li>', array_map('e', $lowStock)) . '</li></ul>');
    }
}

function order_items_html(array $order): string
{
    $h = '<table cellpadding="6" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:14px">';
    foreach (jdec($order['items'], []) as $it) {
        $h .= '<tr><td style="border-bottom:1px solid #eee">' . e($it['name']) . ($it['variant'] ? ' — ' . e($it['variant']) : '') . ' × ' . (int)$it['qty'] . '</td><td align="right" style="border-bottom:1px solid #eee">' . e(money($it['price'] * $it['qty'])) . '</td></tr>';
    }
    $h .= '<tr><td>Subtotal</td><td align="right">' . e(money($order['subtotal'])) . '</td></tr>';
    if ((float)$order['discount'] > 0) $h .= '<tr><td>Descuento / Discount (' . e($order['coupon']) . ')</td><td align="right">-' . e(money($order['discount'])) . '</td></tr>';
    $h .= '<tr><td>Envío / Shipping</td><td align="right">' . e(money($order['shipping'])) . '</td></tr>';
    $h .= '<tr><td><strong>Total</strong></td><td align="right"><strong>' . e(money($order['total'])) . '</strong></td></tr></table>';
    return $h;
}

function notify_order(array $order): void
{
    if (setting_on('notify_order', true)) {
        $body = '<h2>Nuevo pedido pagado ' . e($order['number']) . '</h2>'
            . '<p><strong>' . e($order['name']) . '</strong> · ' . e($order['email']) . ' · ' . e($order['phone']) . '</p>'
            . '<p>' . nl2br(e((string)$order['address'])) . '</p>'
            . ($order['gift_message'] ? '<p><em>Mensaje de regalo:</em> ' . e($order['gift_message']) . '</p>' : '')
            . order_items_html($order)
            . '<p><a href="' . e(abs_url('/admin/orders/view?id=' . $order['id'])) . '">Ver en el panel</a></p>';
        notify_admin('Nuevo pedido ' . $order['number'] . ' — ' . money($order['total']), $body);
    }
    if ($order['email'] !== '') {
        $es = $order['lang'] === 'es';
        $subject = ($es ? 'Gracias por tu compra — pedido ' : 'Thank you for your order — ') . $order['number'];
        $body = '<div style="font-family:Arial,sans-serif;color:#4B2635;max-width:560px">'
            . '<img src="' . e(abs_url(brand_logo())) . '" alt="" width="90" style="border-radius:50%"><h2 style="color:#B83A6B">' . ($es ? '¡Gracias por tu compra!' : 'Thank you for your order!') . '</h2>'
            . '<p>' . ($es ? 'Tu pedido ' : 'Your order ') . '<strong>' . e($order['number']) . '</strong> ' . ($es ? 'está confirmado. Te avisaremos cuando salga.' : 'is confirmed. We\'ll let you know when it ships.') . '</p>'
            . order_items_html($order)
            . '<p>' . ($es ? '¿Preguntas? Escríbenos por WhatsApp: ' : 'Questions? WhatsApp us: ') . '<a href="' . e(wa_link()) . '">' . e(wa_display()) . '</a></p>'
            . '<p>Sofi Love &amp; Faith</p></div>';
        send_mail($order['email'], $subject, $body);
    }
}

/* ---------- Brand ---------- */

function brand_logo(): string
{
    return (string)setting('logo', 'assets/img/logo.jpg') ?: 'assets/img/logo.jpg';
}

function brand_favicon(): string
{
    if (setting_on('favicon_from_logo', true) || !setting('favicon', '')) return brand_logo();
    return (string)setting('favicon');
}
