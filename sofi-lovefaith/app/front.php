<?php
declare(strict_types=1);

function front_route(string $path): void
{
    if (preg_match('#^/product/([A-Za-z0-9-]+)$#', $path, $m)) {
        front_product($m[1]);
        return;
    }
    switch ($path) {
        case '/': front_page('home'); return;
        case '/shop': front_page('shop'); return;
        case '/contact': is_post() ? front_contact_submit() : front_page('contact'); return;
        case '/subscribe': front_subscribe(); return;
        case '/review': front_review(); return;
        case '/cart': front_cart(); return;
        case '/cart/add': front_cart_add(); return;
        case '/cart/update': front_cart_update(); return;
        case '/cart/coupon': front_cart_coupon(); return;
        case '/checkout': front_checkout(); return;
        case '/order/success': front_order_success(); return;
        case '/stripe/webhook': front_stripe_webhook(); return;
        case '/sitemap.xml': front_sitemap(); return;
        case '/robots.txt': front_robots(); return;
        case '/og-image.jpg': front_og_image(); return;
    }
    front_404();
}

/* ---------- SEO ---------- */

function seo_meta(array $o = []): array
{
    $l = lang();
    $site = (string)setting('store_name', 'Sofi Love & Faith');
    $title = $o['title'] ?? '';
    if ($title === '') {
        $title = st('seo_title', $site);
    } elseif (empty($o['raw_title'])) {
        $title = str_replace(['%page%', '%site%'], [$title, $site], (string)setting('seo_title_template', '%page% | %site%'));
    }
    $desc = ($o['desc'] ?? '') ?: st('seo_desc');
    $mode = setting('og_mode', 'logo');
    $ogImage = '';
    if (!empty($o['image']) && $mode === 'auto') $ogImage = abs_url($o['image']);
    elseif ($mode === 'custom' && setting('og_image', '')) $ogImage = abs_url((string)setting('og_image'));
    if ($ogImage === '') $ogImage = abs_url('/og-image.jpg') . '?v=' . substr(md5(brand_logo() . setting('og_bg', 'cream')), 0, 8);
    $canonical = abs_url(strtok($_SERVER['REQUEST_URI'] ?? '/', '?') === base_path() . '/' ? '/' : substr((string)strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), strlen(base_path())));
    return [
        'title' => $title,
        'desc' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$desc))), 0, 300),
        'keywords' => st('seo_keywords'),
        'og_title' => $o['og_title'] ?? (($o['title'] ?? '') !== '' ? $title : st('og_title', $title)),
        'og_desc' => $o['og_desc'] ?? (($o['desc'] ?? '') !== '' ? $desc : st('og_desc', (string)$desc)),
        'og_image' => $ogImage,
        'og_type' => $o['type'] ?? 'website',
        'canonical' => $canonical,
        'jsonld' => $o['jsonld'] ?? [],
        'noindex' => !setting_on('seo_index', true) || !empty($o['noindex']),
        'locale' => $l === 'es' ? 'es_US' : 'en_US',
        'alt_locale' => $l === 'es' ? 'en_US' : 'es_US',
    ];
}

function store_jsonld(): array
{
    return array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'JewelryStore',
        'name' => setting('store_name', 'Sofi Love & Faith'),
        'url' => abs_url('/'),
        'logo' => abs_url(brand_logo()),
        'image' => abs_url(brand_logo()),
        'telephone' => wa_display(),
        'priceRange' => setting('biz_price_range', '$$'),
        'address' => setting('biz_country', '') ? ['@type' => 'PostalAddress', 'addressCountry' => setting('biz_country')] : null,
        'sameAs' => array_values(socials()),
    ]);
}

/* ---------- Pages ---------- */

function front_page(string $slug): void
{
    $page = row('SELECT * FROM pages WHERE slug = ?', [$slug]);
    if (!$page) { front_404(); return; }
    $draft = is_admin() && ($_GET['preview'] ?? '') === 'draft';
    $blocks = page_blocks($slug, $draft);
    $pseo = jdec($page['seo'], []);
    $l = lang();
    $meta = seo_meta([
        'title' => $slug === 'home' ? ($pseo['title_' . $l] ?? '') : (($pseo['title_' . $l] ?? '') ?: tr($page, 'title')),
        'raw_title' => $slug === 'home',
        'desc' => $pseo['desc_' . $l] ?? '',
        'jsonld' => $slug === 'home' ? [store_jsonld()] : [],
    ]);
    view('front/layout', ['meta' => $meta, 'active' => $slug, 'content' => render_blocks($blocks), 'preview' => $draft]);
}

function front_product(string $slug): void
{
    $p = row('SELECT * FROM products WHERE slug = ?', [$slug]);
    if (!$p || ($p['status'] !== 'published' && !is_admin())) { front_404(); return; }
    $media = product_media((int)$p['id']);
    $rating = product_rating((int)$p['id']);
    $reviews = rows('SELECT * FROM reviews WHERE product_id = ? AND status = "approved" ORDER BY id DESC LIMIT 12', [$p['id']]);
    $seo = jdec($p['seo'], []);
    $l = lang();
    $cover = product_cover($p);
    $avail = available_stock($p, null);
    $jsonld = [
        '@context' => 'https://schema.org', '@type' => 'Product', 'name' => tr($p, 'name'),
        'image' => array_values(array_map(fn($m) => abs_url($m['kind'] === 'video' ? ($m['poster'] ?: '') : $m['path']), array_filter($media, fn($m) => in_array($m['kind'], ['image', 'gif'], true)))),
        'description' => strip_tags(tr($p, 'description')), 'sku' => $p['sku'],
        'brand' => ['@type' => 'Brand', 'name' => setting('store_name', 'Sofi Love & Faith')],
        'offers' => [
            '@type' => 'Offer', 'url' => abs_url('/product/' . $p['slug']), 'priceCurrency' => setting('currency', 'USD'),
            'price' => number_format((float)$p['price'], 2, '.', ''),
            'availability' => $avail > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'priceValidUntil' => $p['sale_ends_at'] ? date('Y-m-d', strtotime($p['sale_ends_at'])) : date('Y-12-31'),
        ],
    ];
    if ($rating['count'] > 0) $jsonld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $rating['avg'], 'reviewCount' => $rating['count']];
    $meta = seo_meta([
        'title' => ($seo['title_' . $l] ?? '') ?: tr($p, 'name'),
        'raw_title' => !empty($seo['title_' . $l]),
        'desc' => ($seo['desc_' . $l] ?? '') ?: (tr($p, 'tagline') . ' ' . strip_tags(tr($p, 'description'))),
        'image' => $cover, 'type' => 'product', 'jsonld' => [$jsonld],
        'noindex' => $p['status'] !== 'published',
    ]);
    $related = products_list(['limit' => 4, 'exclude' => $p['id'], 'cat' => explode(',', $p['categories'])[0] ?? '']);
    if (count($related) < 4) $related = products_list(['limit' => 4, 'exclude' => $p['id'], 'sort' => 'random']);
    $bundle = [];
    $ids = array_filter(array_map('intval', explode(',', (string)$p['bundle_ids'])));
    if ($ids && !empty(jdec($p['boosters'], [])['bundle'])) $bundle = products_list(['ids' => $ids, 'limit' => 3]);
    $content = render('front/product', compact('p', 'media', 'rating', 'reviews', 'related', 'bundle'));
    view('front/layout', ['meta' => $meta, 'active' => 'shop', 'content' => $content]);
}

function front_404(): void
{
    http_response_code(404);
    $content = '<section class="blk pad-l"><div class="wrap narrow text-blk center"><p class="script lg">oops</p><h1>' . e(t('not_found')) . '</h1><p>' . e(t('not_found_text')) . '</p><a class="btn btn-primary" href="' . e(url('/shop')) . '">' . e(t('shop')) . '</a></div></section>';
    view('front/layout', ['meta' => seo_meta(['title' => t('not_found'), 'noindex' => true]), 'active' => '', 'content' => $content]);
}

/* ---------- Forms ---------- */

function too_fast(string $key, int $seconds = 20): bool
{
    $last = $_SESSION['last_' . $key] ?? 0;
    if (time() - $last < $seconds) return true;
    $_SESSION['last_' . $key] = time();
    return false;
}

function front_contact_submit(): void
{
    check_csrf();
    if (input('website') !== '') redirect('/contact?sent=1#form'); // honeypot
    $data = [
        'name' => trim(mb_substr((string)input('name'), 0, 190)),
        'email' => trim(mb_substr((string)input('email'), 0, 190)),
        'phone' => trim(mb_substr((string)input('phone'), 0, 60)),
        'subject' => trim(mb_substr((string)input('subject'), 0, 190)),
        'message' => trim(mb_substr((string)input('message'), 0, 5000)),
    ];
    if ($data['name'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL) || $data['message'] === '') {
        $_SESSION['form_old'] = $data;
        flash(t('form_error'), 'error');
        redirect('/contact#form');
    }
    if (too_fast('contact')) redirect('/contact?sent=1#form');
    $id = insert('submissions', $data + [
        'form' => 'contact', 'newsletter' => input('newsletter') ? 1 : 0, 'lang' => lang(),
        'page' => '/contact', 'status' => 'new', 'ip' => client_ip(), 'created_at' => date('Y-m-d H:i:s'),
    ]);
    if (setting_on('notify_form', true)) {
        $html = '<h2>Nuevo mensaje del formulario de contacto</h2><table cellpadding="6" style="font-size:14px">'
            . '<tr><td><b>Nombre</b></td><td>' . e($data['name']) . '</td></tr>'
            . '<tr><td><b>Correo</b></td><td>' . e($data['email']) . '</td></tr>'
            . '<tr><td><b>Teléfono</b></td><td>' . e($data['phone']) . '</td></tr>'
            . '<tr><td><b>Asunto</b></td><td>' . e($data['subject']) . '</td></tr></table>'
            . '<p style="white-space:pre-line;background:#f7f1f3;padding:12px;border-radius:8px">' . e($data['message']) . '</p>'
            . '<p><a href="' . e(abs_url('/admin/forms?id=' . $id)) . '">Ver en el panel</a></p>';
        notify_admin('Nuevo mensaje de ' . $data['name'], $html, $data['email']);
    }
    flash(t('form_thanks'));
    redirect('/contact?sent=1#form');
}

function front_subscribe(): void
{
    check_csrf();
    $email = trim((string)input('email'));
    $back = $_SERVER['HTTP_REFERER'] ?? url('/');
    if (input('website') === '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !too_fast('subscribe', 10)) {
        if (!val('SELECT id FROM submissions WHERE form = "newsletter" AND email = ?', [$email])) {
            insert('submissions', ['form' => 'newsletter', 'email' => $email, 'newsletter' => 1, 'lang' => lang(), 'status' => 'new', 'ip' => client_ip(), 'created_at' => date('Y-m-d H:i:s')]);
        }
        flash(t('form_thanks'));
    }
    header('Location: ' . $back);
    exit;
}

function front_review(): void
{
    check_csrf();
    $p = row('SELECT * FROM products WHERE id = ?', [(int)input('product_id')]);
    if (!$p) front_404();
    $name = trim(mb_substr((string)input('name'), 0, 120));
    $body = trim(mb_substr((string)input('body'), 0, 3000));
    if (input('website') === '' && $name !== '' && $body !== '' && !too_fast('review', 30)) {
        $id = insert('reviews', [
            'product_id' => $p['id'], 'name' => $name, 'email' => mb_substr((string)input('email'), 0, 190),
            'rating' => max(1, min(5, (int)input('rating', 5))), 'title' => mb_substr((string)input('title'), 0, 190),
            'body' => $body, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        if (setting_on('notify_review', true)) notify_admin('Nueva reseña por aprobar — ' . $p['name_en'], '<p><b>' . e($name) . '</b> (' . (int)input('rating', 5) . '★)</p><p>' . e($body) . '</p><p><a href="' . e(abs_url('/admin/forms?tab=reviews')) . '">Aprobar en el panel</a></p>');
        flash(t('review_thanks'));
    }
    redirect('/product/' . $p['slug'] . '#reviews');
}

/* ---------- Cart & checkout ---------- */

function front_cart(): void
{
    $sum = cart_summary();
    $content = render('front/cart', ['sum' => $sum]);
    view('front/layout', ['meta' => seo_meta(['title' => t('your_cart'), 'noindex' => true]), 'active' => 'cart', 'content' => $content]);
}

function front_cart_add(): void
{
    check_csrf();
    $ok = false;
    $ids = $_POST['bundle'] ?? null;
    if (is_array($ids)) {
        $ids = array_map('intval', $ids);
        foreach ($ids as $id) $ok = cart_add($id, '', 1) || $ok;
        $_SESSION['bundle'] = ['ids' => $ids, 'pct' => max(0, min(90, (int)input('bundle_pct', 0)))];
    } else {
        $ok = cart_add((int)input('product_id'), (string)input('variant'), (int)input('qty', 1));
    }
    if (input('gift_message') !== '') $_SESSION['gift_message'] = mb_substr((string)input('gift_message'), 0, 500);
    if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') json_out(['ok' => $ok, 'count' => cart_count()]);
    if (input('buy_now')) {
        if (stripe_configured()) {
            $_POST['_from_buy_now'] = 1;
            front_checkout();
            return;
        }
    }
    flash($ok ? t('added') : t('out_of_stock'), $ok ? 'ok' : 'error');
    redirect('/cart');
}

function front_cart_update(): void
{
    check_csrf();
    foreach ((array)($_POST['qty'] ?? []) as $key => $qty) cart_set((string)$key, (int)$qty);
    if ($rm = input('remove')) cart_set((string)$rm, 0);
    if (isset($_POST['gift_message'])) $_SESSION['gift_message'] = mb_substr((string)$_POST['gift_message'], 0, 500);
    redirect('/cart');
}

function front_cart_coupon(): void
{
    check_csrf();
    $code = strtoupper(trim((string)input('code')));
    if ($code === '' || input('remove_coupon')) {
        unset($_SESSION['coupon']);
        redirect('/cart');
    }
    $c = coupon_find($code);
    if ($c) {
        $_SESSION['coupon'] = $c['code'];
        $sum = cart_summary();
        if ($sum['coupon']) flash(t('coupon_ok'));
        elseif ((float)$c['min_total'] > $sum['subtotal']) flash(t('coupon_min', ['amt' => money($c['min_total'])]), 'error');
        else flash(t('coupon_na'), 'error');
    } else { unset($_SESSION['coupon']); flash(t('coupon_bad'), 'error'); }
    redirect('/cart');
}

function front_checkout(): void
{
    check_csrf();
    $sum = cart_summary();
    if (!$sum['lines']) redirect('/cart');
    if (!stripe_configured()) redirect('/cart');
    $order = order_create($sum, (string)($_SESSION['gift_message'] ?? ''));
    try {
        $urlTo = stripe_checkout($order, $sum);
    } catch (Throwable $ex) {
        error_log('[stripe] ' . $ex->getMessage());
        update('orders', ['status' => 'failed', 'note' => $ex->getMessage()], 'id = ?', [$order['id']]);
        flash((lang() === 'es' ? 'No pudimos iniciar el pago. ' : 'We could not start the payment. ') . (is_admin() ? $ex->getMessage() : ''), 'error');
        redirect('/cart');
    }
    $_SESSION['last_order'] = $order['number'];
    header('Location: ' . $urlTo, true, 303);
    exit;
}

function front_order_success(): void
{
    $n = (string)input('n');
    $order = row('SELECT * FROM orders WHERE number = ?', [$n]);
    $paid = false;
    if ($order && ($_SESSION['last_order'] ?? '') === $n) {
        if ($order['status'] === 'pending' && $order['stripe_session'] !== '' && stripe_configured()) {
            try {
                $s = stripe_request('GET', 'checkout/sessions/' . rawurlencode($order['stripe_session']));
                if (($s['payment_status'] ?? '') === 'paid') order_mark_paid($order, stripe_session_info($s));
            } catch (Throwable $ex) {
                error_log('[stripe] ' . $ex->getMessage());
            }
            $order = row('SELECT * FROM orders WHERE id = ?', [$order['id']]);
        }
        $paid = $order['status'] === 'paid';
        if ($paid) {
            unset($_SESSION['cart'], $_SESSION['coupon'], $_SESSION['gift_message'], $_SESSION['bundle']);
        }
    } else {
        $order = null;
    }
    $content = render('front/success', ['order' => $order, 'paid' => $paid]);
    view('front/layout', ['meta' => seo_meta(['title' => t('thanks_order'), 'noindex' => true]), 'active' => '', 'content' => $content]);
}

function front_stripe_webhook(): void
{
    $payload = (string)file_get_contents('php://input');
    $secret = trim((string)setting('stripe_whsec_' . stripe_mode(), ''));
    if ($secret === '' || !stripe_verify_webhook($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $secret)) {
        http_response_code(400);
        exit('invalid signature');
    }
    $event = json_decode($payload, true) ?: [];
    if (in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
        $s = $event['data']['object'] ?? [];
        $order = row('SELECT * FROM orders WHERE stripe_session = ? OR number = ?', [$s['id'] ?? '', $s['client_reference_id'] ?? '']);
        if ($order && ($s['payment_status'] ?? '') === 'paid') order_mark_paid($order, stripe_session_info($s));
    }
    json_out(['received' => true]);
}

/* ---------- SEO files ---------- */

function front_sitemap(): void
{
    if (!setting_on('seo_sitemap', true)) front_404();
    header('Content-Type: application/xml; charset=utf-8');
    $urls = ['/', '/shop', '/contact'];
    foreach (rows('SELECT slug FROM products WHERE status = "published"') as $p) $urls[] = '/product/' . $p['slug'];
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    foreach ($urls as $u) {
        $loc = abs_url($u);
        echo '<url><loc>' . e($loc) . '</loc>';
        foreach (LANGS as $l) echo '<xhtml:link rel="alternate" hreflang="' . $l . '" href="' . e($loc . '?lang=' . $l) . '"/>';
        echo "</url>\n";
    }
    echo '</urlset>';
    exit;
}

function front_robots(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    if (!setting_on('seo_index', true)) {
        echo "User-agent: *\nDisallow: /\n";
        exit;
    }
    echo "User-agent: *\nDisallow: /admin\nDisallow: /cart\nDisallow: /checkout\n";
    if (setting_on('seo_sitemap', true)) echo 'Sitemap: ' . abs_url('/sitemap.xml') . "\n";
    exit;
}

/** 1200×630 share image: the site logo centred on the brand colour (generated with GD, cached). */
function front_og_image(): void
{
    $logo = ROOT . '/' . ltrim(brand_logo(), '/');
    $bgName = (string)setting('og_bg', 'cream');
    $bg = ['cream' => [253, 247, 245], 'blush' => [248, 225, 231], 'plum' => [75, 38, 53]][$bgName] ?? [253, 247, 245];
    $cache = ROOT . '/uploads/og-' . substr(md5(brand_logo() . $bgName . @filemtime($logo)), 0, 12) . '.jpg';
    if (!is_file($cache) && function_exists('imagecreatetruecolor') && is_file($logo)) {
        $im = imagecreatetruecolor(1200, 630);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$bg));
        $src = @imagecreatefromstring((string)file_get_contents($logo));
        if ($src) {
            $sw = imagesx($src); $sh = imagesy($src);
            $size = 520;
            $ratio = min($size / $sw, $size / $sh);
            $w = (int)($sw * $ratio); $h = (int)($sh * $ratio);
            imagecopyresampled($im, $src, (int)((1200 - $w) / 2), (int)((630 - $h) / 2), 0, 0, $w, $h, $sw, $sh);
            imagedestroy($src);
        }
        @imagejpeg($im, $cache, 88);
        imagedestroy($im);
    }
    $file = is_file($cache) ? $cache : $logo;
    header('Content-Type: ' . (str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}
