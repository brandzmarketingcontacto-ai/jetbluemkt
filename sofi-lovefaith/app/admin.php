<?php
declare(strict_types=1);

/* =========================================================
 * Super-admin dashboard (password only, no username)
 * ========================================================= */

function admin_route(string $path): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: SAMEORIGIN');
    if ($path === '/login') { admin_login(); return; }
    if ($path === '/logout') { admin_logout(); return; }
    if (!is_admin()) {
        if (str_starts_with($path, '/api/')) json_out(['error' => 'Sesión expirada. Vuelve a entrar.'], 401);
        redirect('/admin/login');
    }
    if (is_post()) check_csrf();
    $routes = [
        '/' => 'admin_dashboard',
        '/pages' => 'admin_pages',
        '/products' => 'admin_products',
        '/products/edit' => 'admin_product_edit',
        '/products/action' => 'admin_product_action',
        '/categories' => 'admin_categories',
        '/orders' => 'admin_orders',
        '/orders/view' => 'admin_order_view',
        '/forms' => 'admin_forms',
        '/forms/export' => 'admin_forms_export',
        '/offers' => 'admin_offers',
        '/media' => 'admin_media',
        '/seo' => 'admin_seo',
        '/settings' => 'admin_settings',
        '/backup' => 'admin_backup',
        '/api/page' => 'api_page_save',
        '/api/upload' => 'api_upload',
        '/api/media' => 'api_media',
        '/api/smtp-test' => 'api_smtp_test',
        '/api/stripe-test' => 'api_stripe_test',
    ];
    $fn = $routes[$path] ?? null;
    if (!$fn) { http_response_code(404); admin_page('No encontrado', '', '<div class="card"><p>Esta sección no existe.</p></div>'); return; }
    $fn();
}

function admin_page(string $title, string $active, string $content, array $extra = []): void
{
    view('admin/layout', ['title' => $title, 'active' => $active, 'content' => $content] + $extra);
}

function admin_nav_counts(): array
{
    return [
        'forms' => (int)val('SELECT COUNT(*) FROM submissions WHERE status = "new" AND form = "contact"') + (int)val('SELECT COUNT(*) FROM reviews WHERE status = "pending"'),
        'orders' => (int)val('SELECT COUNT(*) FROM orders WHERE status = "paid"'),
    ];
}

/* ---------- Auth ---------- */

function admin_login(): void
{
    if (is_admin()) redirect('/admin');
    $error = '';
    $ip = client_ip();
    $att = row('SELECT * FROM login_attempts WHERE ip = ?', [$ip]);
    $locked = $att && $att['locked_until'] && strtotime($att['locked_until']) > time();
    if (is_post() && !$locked) {
        check_csrf();
        $pass = (string)input('password');
        if (password_verify($pass, (string)setting('admin_password_hash', ''))) {
            q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['admin_ver'] = substr((string)setting('admin_password_hash', ''), -12);
            if (input('remember')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), session_id(), ['expires' => time() + 60 * 60 * 24 * 14, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax', 'secure' => $p['secure']]);
            }
            redirect('/admin');
        }
        $n = (int)($att['attempts'] ?? 0) + 1;
        $lockUntil = $n >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        q('INSERT INTO login_attempts (ip, attempts, locked_until) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = VALUES(locked_until)', [$ip, $lockUntil ? 0 : $n, $lockUntil]);
        $error = $lockUntil ? 'Demasiados intentos. Espera 15 minutos.' : 'Contraseña incorrecta. Te quedan ' . (5 - $n) . ' intentos.';
        $locked = (bool)$lockUntil;
    } elseif ($locked) {
        $error = 'Demasiados intentos. Espera 15 minutos.';
    }
    view('admin/login', ['error' => $error, 'locked' => $locked]);
}

function admin_logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    redirect('/admin/login');
}

/* ---------- Dashboard ---------- */

function admin_dashboard(): void
{
    $stats = [
        'sales_month' => (float)val('SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ("paid","shipped","delivered") AND paid_at >= ?', [date('Y-m-01')]),
        'orders_month' => (int)val('SELECT COUNT(*) FROM orders WHERE status IN ("paid","shipped","delivered") AND paid_at >= ?', [date('Y-m-01')]),
        'products' => (int)val('SELECT COUNT(*) FROM products WHERE status = "published"'),
        'messages' => (int)val('SELECT COUNT(*) FROM submissions WHERE status = "new" AND form = "contact"'),
    ];
    $recent = rows('SELECT * FROM orders WHERE status <> "pending" AND status <> "failed" ORDER BY id DESC LIMIT 6');
    $low = rows('SELECT * FROM products WHERE status = "published" AND track_stock = 1 AND stock <= ? ORDER BY stock ASC LIMIT 6', [(int)setting('low_stock_at', '5')]);
    $msgs = rows('SELECT * FROM submissions WHERE form = "contact" ORDER BY id DESC LIMIT 5');
    admin_page('Resumen', 'dashboard', render('admin/dashboard', compact('stats', 'recent', 'low', 'msgs')));
}

/* ---------- Page builder ---------- */

function admin_pages(): void
{
    $slug = (string)input('slug', 'home');
    $page = row('SELECT * FROM pages WHERE slug = ?', [$slug]) ?? row('SELECT * FROM pages WHERE slug = "home"');
    $pages = rows('SELECT slug, title_es FROM pages ORDER BY FIELD(slug, "home", "shop", "contact"), id');
    $cats = rows('SELECT slug, name_es FROM categories ORDER BY sort, id');
    admin_page('Editor de páginas', 'pages', render('admin/builder', compact('page', 'pages', 'cats')), ['wide' => true]);
}

function api_page_save(): void
{
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals(csrf_token(), (string)($body['_csrf'] ?? ''))) json_out(['error' => 'Sesión expirada, recarga la página.'], 419);
    $slug = (string)($body['slug'] ?? '');
    $page = row('SELECT * FROM pages WHERE slug = ?', [$slug]);
    if (!$page) json_out(['error' => 'Página no encontrada'], 404);
    $schemas = block_schemas();
    $clean = [];
    foreach ((array)($body['blocks'] ?? []) as $b) {
        $type = (string)($b['type'] ?? '');
        if (!isset($schemas[$type])) continue;
        $nb = ['id' => preg_replace('/[^a-z0-9]/i', '', (string)($b['id'] ?? '')) ?: bid(), 'type' => $type, 'visible' => !empty($b['visible']), 'data' => [], 'i18n' => ['en' => [], 'es' => []], 'style' => []];
        foreach ($schemas[$type]['fields'] as $f) {
            [$key, , $ftype, $translatable] = $f;
            if ($translatable) {
                foreach (LANGS as $l) $nb['i18n'][$l][$key] = mb_substr((string)($b['i18n'][$l][$key] ?? ''), 0, 20000);
            } else {
                $v = $b['data'][$key] ?? '';
                if ($ftype === 'images') $v = array_values(array_filter(array_map('strval', (array)$v)));
                elseif ($ftype === 'toggle') $v = (bool)$v;
                elseif ($ftype === 'number') $v = $v === '' ? '' : (int)$v;
                else $v = mb_substr((string)$v, 0, 2000);
                $nb['data'][$key] = $v;
            }
        }
        $st = (array)($b['style'] ?? []);
        $nb['style'] = [
            'bg' => in_array($st['bg'] ?? '', ['default', 'white', 'blush', 'plum', 'rose'], true) ? $st['bg'] : 'default',
            'pad' => in_array($st['pad'] ?? '', ['none', 's', 'm', 'l'], true) ? $st['pad'] : 'm',
            'hide_mobile' => !empty($st['hide_mobile']),
        ];
        $clean[] = $nb;
    }
    $seo = [];
    foreach (['title_en', 'title_es', 'desc_en', 'desc_es'] as $k) $seo[$k] = mb_substr((string)($body['seo'][$k] ?? ''), 0, 300);
    $data = ['blocks_draft' => jenc($clean), 'seo' => jenc($seo), 'updated_at' => date('Y-m-d H:i:s')];
    if (!empty($body['publish'])) { $data['blocks'] = jenc($clean); $data['blocks_draft'] = null; }
    update('pages', $data, 'id = ?', [$page['id']]);
    json_out(['ok' => true, 'published' => !empty($body['publish']), 'saved_at' => date('H:i')]);
}

/* ---------- Media ---------- */

function api_upload(): void
{
    if (empty($_FILES['files'])) {
        $max = ini_get('post_max_size');
        json_out(['error' => 'No se recibió el archivo. Puede ser más grande que el límite del hosting (' . $max . ').'], 400);
    }
    $out = [];
    $errors = [];
    foreach (files_list($_FILES['files']) as $file) {
        try { $out[] = store_upload($file); }
        catch (Throwable $ex) { $errors[] = ($file['name'] ?? '') . ': ' . $ex->getMessage(); }
    }
    json_out(['files' => $out, 'errors' => $errors]);
}

function api_media(): void
{
    $kind = (string)input('kind', '');
    $sql = 'SELECT * FROM media';
    $params = [];
    if ($kind === 'image') $sql .= ' WHERE kind IN ("image","gif")';
    elseif ($kind === 'video') $sql .= ' WHERE kind = "video"';
    $sql .= ' ORDER BY id DESC LIMIT 300';
    $list = array_map(fn($m) => ['id' => (int)$m['id'], 'path' => $m['path'], 'url' => media_url($m['path']), 'kind' => $m['kind'], 'name' => $m['original']], rows($sql, $params));
    json_out(['files' => $list]);
}

function admin_media(): void
{
    if (is_post() && input('delete')) {
        media_delete((int)input('delete'));
        flash('Archivo eliminado de la biblioteca.');
        redirect('/admin/media');
    }
    $items = rows('SELECT * FROM media ORDER BY id DESC LIMIT 500');
    admin_page('Biblioteca de medios', 'media', render('admin/media', compact('items')));
}

/* ---------- Products ---------- */

function admin_products(): void
{
    $q = trim((string)input('q'));
    $cat = (string)input('cat');
    $status = (string)input('status');
    $where = ['1=1'];
    $params = [];
    if ($q !== '') { $where[] = '(name_en LIKE ? OR name_es LIKE ? OR sku LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
    if ($cat !== '') { $where[] = 'FIND_IN_SET(?, categories)'; $params[] = $cat; }
    if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
    $list = rows('SELECT p.*, (SELECT COUNT(*) FROM product_media m WHERE m.product_id = p.id) AS media_n FROM products p WHERE ' . implode(' AND ', $where) . ' ORDER BY sort, id DESC', $params);
    $cats = rows('SELECT * FROM categories ORDER BY sort, id');
    $counts = [
        'active' => (int)val('SELECT COUNT(*) FROM products WHERE status = "published"'),
        'sale' => (int)val('SELECT COUNT(*) FROM products WHERE status = "published" AND compare_price > price'),
        'low' => (int)val('SELECT COUNT(*) FROM products WHERE status = "published" AND track_stock = 1 AND stock <= ?', [(int)setting('low_stock_at', '5')]),
        'draft' => (int)val('SELECT COUNT(*) FROM products WHERE status = "draft"'),
    ];
    admin_page('Productos', 'products', render('admin/products', compact('list', 'cats', 'counts', 'q', 'cat', 'status')));
}

function admin_product_action(): void
{
    $do = (string)input('do');
    // a per-row button (?id=) wins over the bulk checkboxes
    $ids = isset($_GET['id']) ? [(int)$_GET['id']] : array_map('intval', (array)($_POST['ids'] ?? []));
    $ids = array_filter($ids);
    if (!$ids) redirect('/admin/products');
    foreach ($ids as $id) {
        $p = row('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$p) continue;
        if ($do === 'delete') {
            q('DELETE FROM product_media WHERE product_id = ?', [$id]);
            q('DELETE FROM reviews WHERE product_id = ?', [$id]);
            q('DELETE FROM products WHERE id = ?', [$id]);
        } elseif ($do === 'duplicate') {
            $copy = $p;
            unset($copy['id']);
            $copy['slug'] = unique_slug($p['slug'] . '-copy');
            $copy['name_en'] .= ' (copy)';
            $copy['name_es'] .= ' (copia)';
            $copy['status'] = 'draft';
            $copy['created_at'] = $copy['updated_at'] = date('Y-m-d H:i:s');
            $nid = insert('products', $copy);
            foreach (product_media($id) as $m) { unset($m['id']); $m['product_id'] = $nid; insert('product_media', $m); }
        } elseif (in_array($do, ['published', 'draft', 'hidden'], true)) {
            update('products', ['status' => $do], 'id = ?', [$id]);
        } elseif ($do === 'sort') {
            update('products', ['sort' => (int)input('sort')], 'id = ?', [$id]);
        }
    }
    if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') json_out(['ok' => true]);
    flash(match ($do) { 'delete' => 'Producto eliminado.', 'duplicate' => 'Producto duplicado como borrador.', default => 'Productos actualizados.' });
    redirect('/admin/products');
}

function unique_slug(string $slug, int $exceptId = 0): string
{
    $base = slugify($slug);
    $s = $base;
    $i = 2;
    while (val('SELECT id FROM products WHERE slug = ? AND id <> ?', [$s, $exceptId])) $s = $base . '-' . $i++;
    return $s;
}

function admin_product_edit(): void
{
    $id = (int)input('id');
    $p = $id ? row('SELECT * FROM products WHERE id = ?', [$id]) : null;
    if ($id && !$p) redirect('/admin/products');

    if (is_post()) {
        $num = fn($k) => ($v = trim((string)input($k))) === '' ? null : round((float)str_replace([',', '$'], ['', ''], $v), 2);
        $nameEn = trim((string)input('name_en'));
        $nameEs = trim((string)input('name_es'));
        if ($nameEn === '' && $nameEs === '') { flash('Escribe el nombre del producto.', 'error'); redirect('/admin/products/edit' . ($id ? '?id=' . $id : '')); }
        $boostKeys = ['countdown', 'lowstock', 'gift', 'whatsapp', 'bundle', 'reviews', 'sticky'];
        $boost = [];
        foreach ($boostKeys as $k) $boost[$k] = input('boost_' . $k) ? 1 : 0;
        $boost['bundle_pct'] = max(0, min(90, (int)input('bundle_pct', 15)));
        $cats = array_filter(array_map('strval', (array)($_POST['cats'] ?? [])), fn($c) => preg_match('/^[a-z0-9-]+$/', $c));
        $options = jdec((string)input('options_json'), []);
        $variants = jdec((string)input('variants_json'), []);
        $sale = trim((string)input('sale_ends_at'));
        $data = [
            'name_en' => mb_substr($nameEn ?: $nameEs, 0, 190), 'name_es' => mb_substr($nameEs ?: $nameEn, 0, 190),
            'tagline_en' => mb_substr((string)input('tagline_en'), 0, 255), 'tagline_es' => mb_substr((string)input('tagline_es'), 0, 255),
            'description_en' => safe_html((string)input('description_en')), 'description_es' => safe_html((string)input('description_es')),
            'details_en' => (string)input('details_en'), 'details_es' => (string)input('details_es'),
            'badge_en' => mb_substr((string)input('badge_en'), 0, 40), 'badge_es' => mb_substr((string)input('badge_es'), 0, 40),
            'sku' => mb_substr((string)input('sku'), 0, 80),
            'price' => $num('price') ?? 0, 'compare_price' => $num('compare_price'), 'cost' => $num('cost'),
            'stock' => (int)input('stock', 0), 'track_stock' => input('track_stock') ? 1 : 0, 'allow_backorder' => input('allow_backorder') ? 1 : 0,
            'categories' => implode(',', $cats), 'type' => mb_substr((string)input('type'), 0, 60),
            'material' => mb_substr((string)input('material'), 0, 255), 'tags' => mb_substr((string)input('tags'), 0, 255),
            'status' => in_array(input('status'), ['published', 'draft', 'hidden'], true) ? input('status') : 'draft',
            'featured' => input('featured') ? 1 : 0,
            'sale_ends_at' => $sale !== '' ? date('Y-m-d H:i:s', strtotime($sale)) : null,
            'options' => jenc(is_array($options) ? $options : []), 'variants' => jenc(is_array($variants) ? $variants : []),
            'boosters' => jenc($boost),
            'bundle_ids' => implode(',', array_filter(array_map('intval', (array)($_POST['bundle_ids'] ?? [])))),
            'seo' => jenc(['title_en' => (string)input('seo_title_en'), 'title_es' => (string)input('seo_title_es'), 'desc_en' => (string)input('seo_desc_en'), 'desc_es' => (string)input('seo_desc_es')]),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $data['slug'] = unique_slug((string)input('slug') ?: ($data['name_en'] ?: $data['name_es']), $id);
        if ($id) update('products', $data, 'id = ?', [$id]);
        else { $data['created_at'] = $data['updated_at']; $data['sort'] = (int)val('SELECT COALESCE(MAX(sort),0)+1 FROM products'); $id = insert('products', $data); }

        // media (sorted list from the editor)
        q('DELETE FROM product_media WHERE product_id = ?', [$id]);
        foreach (jdec((string)input('media_json'), []) as $i => $m) {
            $path = (string)($m['path'] ?? '');
            $kind = in_array($m['kind'] ?? '', ['image', 'gif', 'video', 'embed'], true) ? $m['kind'] : 'image';
            if ($path === '' || str_contains($path, '..')) continue;
            if ($kind !== 'embed' && !preg_match('#^(uploads|assets)/#', $path)) continue;
            insert('product_media', ['product_id' => $id, 'path' => mb_substr($path, 0, 500), 'kind' => $kind, 'poster' => mb_substr((string)($m['poster'] ?? ''), 0, 500) ?: null,
                'alt_en' => mb_substr((string)($m['alt_en'] ?? ''), 0, 255), 'alt_es' => mb_substr((string)($m['alt_es'] ?? ''), 0, 255), 'sort' => $i]);
        }
        flash('Producto guardado.');
        redirect('/admin/products/edit?id=' . $id . (input('_tab') ? '#' . preg_replace('/[^a-z-]/', '', (string)input('_tab')) : ''));
    }

    $media = $p ? product_media((int)$p['id']) : [];
    $cats = rows('SELECT * FROM categories ORDER BY sort, id');
    $all = rows('SELECT id, name_es, name_en, status FROM products WHERE id <> ? ORDER BY name_es', [$id]);
    $covers = [];
    foreach ($all as $a) $covers[$a['id']] = product_cover($a);
    admin_page($p ? 'Editar producto' : 'Nuevo producto', 'products', render('admin/product_edit', compact('p', 'media', 'cats', 'all', 'covers')), ['wide' => true]);
}

function admin_categories(): void
{
    if (is_post()) {
        $do = (string)input('do');
        if ($do === 'save') {
            foreach ((array)($_POST['cat'] ?? []) as $cid => $c) {
                $d = ['name_en' => mb_substr((string)($c['name_en'] ?? ''), 0, 120), 'name_es' => mb_substr((string)($c['name_es'] ?? ''), 0, 120), 'image' => (string)($c['image'] ?? '') ?: null, 'sort' => (int)($c['sort'] ?? 0)];
                update('categories', $d, 'id = ?', [(int)$cid]);
            }
            $new = trim((string)input('new_en'));
            if ($new !== '') {
                $slug = slugify($new);
                if (!val('SELECT id FROM categories WHERE slug = ?', [$slug])) {
                    insert('categories', ['slug' => $slug, 'name_en' => $new, 'name_es' => trim((string)input('new_es')) ?: $new, 'sort' => (int)val('SELECT COALESCE(MAX(sort),0)+1 FROM categories')]);
                }
            }
            flash('Categorías guardadas.');
        } elseif ($do === 'delete') {
            q('DELETE FROM categories WHERE id = ?', [(int)input('id')]);
            flash('Categoría eliminada (los productos no se borran).');
        }
        redirect('/admin/categories');
    }
    $cats = rows('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE FIND_IN_SET(c.slug, p.categories)) n FROM categories c ORDER BY sort, id');
    admin_page('Categorías', 'products', render('admin/categories', compact('cats')));
}

/* ---------- Orders ---------- */

const ORDER_STATUSES = ['pending' => 'Pago pendiente', 'paid' => 'Pagado', 'shipped' => 'Enviado', 'delivered' => 'Entregado', 'cancelled' => 'Cancelado', 'failed' => 'Fallido'];

function admin_orders(): void
{
    $status = (string)input('status');
    $list = $status !== '' ? rows('SELECT * FROM orders WHERE status = ? ORDER BY id DESC LIMIT 300', [$status]) : rows('SELECT * FROM orders WHERE status NOT IN ("pending","failed") OR created_at >= ? ORDER BY id DESC LIMIT 300', [date('Y-m-d', strtotime('-2 days'))]);
    admin_page('Pedidos', 'orders', render('admin/orders', compact('list', 'status')));
}

function admin_order_view(): void
{
    $o = row('SELECT * FROM orders WHERE id = ?', [(int)input('id')]);
    if (!$o) redirect('/admin/orders');
    if (is_post()) {
        $st = (string)input('status');
        $d = ['note' => (string)input('note')];
        if (isset(ORDER_STATUSES[$st])) $d['status'] = $st;
        update('orders', $d, 'id = ?', [$o['id']]);
        if ($st === 'shipped' && $o['status'] !== 'shipped' && input('notify_customer') && $o['email']) {
            $es = $o['lang'] === 'es';
            send_mail($o['email'], ($es ? 'Tu pedido va en camino — ' : 'Your order is on its way — ') . $o['number'],
                '<div style="font-family:Arial,sans-serif;color:#4B2635"><h2 style="color:#B83A6B">' . ($es ? '¡Tu pedido va en camino!' : 'Your order is on its way!') . '</h2><p>' . nl2br(e((string)input('note'))) . '</p>' . order_items_html($o) . '</div>');
        }
        flash('Pedido actualizado.');
        redirect('/admin/orders/view?id=' . $o['id']);
    }
    admin_page('Pedido ' . $o['number'], 'orders', render('admin/order_view', ['o' => $o]));
}

/* ---------- Forms ---------- */

function admin_forms(): void
{
    $tab = (string)input('tab', 'contact');
    if (is_post()) {
        $id = (int)input('id');
        $do = (string)input('do');
        if ($tab === 'reviews') {
            if ($do === 'approve') update('reviews', ['status' => 'approved'], 'id = ?', [$id]);
            if ($do === 'hide') update('reviews', ['status' => 'pending'], 'id = ?', [$id]);
            if ($do === 'delete') q('DELETE FROM reviews WHERE id = ?', [$id]);
        } else {
            if (in_array($do, ['new', 'done', 'archived'], true)) update('submissions', ['status' => $do], 'id = ?', [$id]);
            if ($do === 'note') update('submissions', ['note' => (string)input('note')], 'id = ?', [$id]);
            if ($do === 'delete') { q('DELETE FROM submissions WHERE id = ?', [$id]); $id = 0; }
        }
        flash('Guardado.');
        redirect('/admin/forms?tab=' . urlencode($tab) . ($id && $do !== 'delete' ? '&id=' . $id : ''));
    }
    $filter = (string)input('f', 'all');
    if ($tab === 'reviews') {
        $list = rows('SELECT r.*, p.name_es AS product, p.slug FROM reviews r LEFT JOIN products p ON p.id = r.product_id ORDER BY r.status = "pending" DESC, r.id DESC LIMIT 300');
        $current = null;
    } else {
        $form = $tab === 'newsletter' ? 'newsletter' : 'contact';
        $sql = 'SELECT * FROM submissions WHERE form = ?';
        $params = [$form];
        if ($filter === 'new') $sql .= ' AND status = "new"';
        elseif ($filter === 'archived') $sql .= ' AND status = "archived"';
        else $sql .= ' AND status <> "archived"';
        $list = rows($sql . ' ORDER BY id DESC LIMIT 500', $params);
        $cid = (int)input('id');
        $current = $cid ? row('SELECT * FROM submissions WHERE id = ?', [$cid]) : ($list[0] ?? null);
    }
    $counts = [
        'contact' => (int)val('SELECT COUNT(*) FROM submissions WHERE form = "contact" AND status = "new"'),
        'newsletter' => (int)val('SELECT COUNT(*) FROM submissions WHERE form = "newsletter"'),
        'reviews' => (int)val('SELECT COUNT(*) FROM reviews WHERE status = "pending"'),
    ];
    admin_page('Formularios', 'forms', render('admin/forms', compact('tab', 'list', 'current', 'counts', 'filter')));
}

function admin_forms_export(): void
{
    $form = input('form') === 'newsletter' ? 'newsletter' : 'contact';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $form . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha', 'Nombre', 'Correo', 'Teléfono', 'Asunto', 'Mensaje', 'Acepta ofertas', 'Idioma', 'Estado']);
    foreach (rows('SELECT * FROM submissions WHERE form = ? ORDER BY id DESC', [$form]) as $s) {
        fputcsv($out, [$s['created_at'], $s['name'], $s['email'], $s['phone'], $s['subject'], $s['message'], $s['newsletter'] ? 'sí' : 'no', $s['lang'], $s['status']]);
    }
    exit;
}

/* ---------- Offers & coupons ---------- */

function admin_offers(): void
{
    if (is_post()) {
        $do = (string)input('do');
        if ($do === 'bar') {
            save_settings(['offer_bar_on', 'offer_bar_en', 'offer_bar_es', 'offer_bar_speed', 'offer_bar_link'], ['offer_bar_on']);
            flash('Barra de ofertas guardada.');
        } elseif ($do === 'coupon') {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)input('code')));
            if ($code === '') { flash('Escribe un código.', 'error'); redirect('/admin/offers'); }
            $d = ['code' => $code, 'type' => input('type') === 'fixed' ? 'fixed' : 'percent', 'value' => (float)input('value'), 'min_total' => (float)input('min_total'),
                'category' => (string)input('category'), 'active' => input('active') ? 1 : 0, 'expires_at' => input('expires_at') ? date('Y-m-d H:i:s', strtotime((string)input('expires_at'))) : null];
            $cid = (int)input('id');
            if ($cid) update('coupons', $d, 'id = ?', [$cid]);
            elseif (val('SELECT id FROM coupons WHERE code = ?', [$code])) { flash('Ese código ya existe.', 'error'); redirect('/admin/offers'); }
            else insert('coupons', $d);
            flash('Cupón guardado.');
        } elseif ($do === 'delete') {
            q('DELETE FROM coupons WHERE id = ?', [(int)input('id')]);
            flash('Cupón eliminado.');
        }
        redirect('/admin/offers');
    }
    $coupons = rows('SELECT * FROM coupons ORDER BY id DESC');
    $cats = rows('SELECT * FROM categories ORDER BY sort, id');
    admin_page('Ofertas y cupones', 'offers', render('admin/offers', compact('coupons', 'cats')));
}

/* ---------- Settings & SEO ---------- */

/** Save whitelisted setting keys from $_POST. Checkbox keys become 1/0; secret keys are only replaced when typed. */
function save_settings(array $keys, array $checkboxes = [], array $secrets = []): void
{
    foreach ($keys as $k) {
        if (in_array($k, $checkboxes, true)) { set_setting($k, input($k) ? '1' : '0'); continue; }
        if (!array_key_exists($k, $_POST)) continue;
        $v = trim((string)$_POST[$k]);
        if (in_array($k, $secrets, true) && $v === '') continue;
        set_setting($k, $v);
    }
}

function admin_settings(): void
{
    if (is_post()) {
        $do = (string)input('do');
        if ($do === 'password') {
            $cur = (string)input('current');
            $new = (string)input('new');
            if (!password_verify($cur, (string)setting('admin_password_hash'))) flash('La contraseña actual no es correcta.', 'error');
            elseif (strlen($new) < 8) flash('La nueva contraseña debe tener al menos 8 caracteres.', 'error');
            elseif ($new !== (string)input('confirm')) flash('Las contraseñas nuevas no coinciden.', 'error');
            else {
                set_setting('admin_password_hash', password_hash($new, PASSWORD_DEFAULT));
                set_setting('admin_password_default', '0');
                $_SESSION['admin_ver'] = substr((string)setting('admin_password_hash'), -12);
                flash('Contraseña actualizada. Úsala la próxima vez que entres.');
            }
            redirect('/admin/settings#seguridad');
        }
        $keys = [
            'logo', 'logo_light', 'favicon', 'favicon_from_logo', 'logo_size', 'logo_shape', 'logo_show_name', 'brand_script', 'brand_sub',
            'store_name', 'brand_handle', 'site_url', 'currency', 'free_shipping_from', 'shipping_flat', 'ship_countries', 'low_stock_at', 'low_stock_show', 'timezone',
            'default_lang', 'lang_toggle', 'ask_lang', 'footer_text_en', 'footer_text_es', 'footer_help_en', 'footer_help_es',
            'care_text_en', 'care_text_es', 'shipping_text_en', 'shipping_text_es',
            'whatsapp_number', 'whatsapp_message_en', 'whatsapp_message_es', 'whatsapp_float',
            'social_instagram', 'social_facebook', 'social_tiktok', 'social_pinterest', 'social_youtube',
            'social_instagram_on', 'social_facebook_on', 'social_tiktok_on', 'social_pinterest_on', 'social_youtube_on',
            'stripe_enabled', 'stripe_mode', 'stripe_pk_test', 'stripe_sk_test', 'stripe_whsec_test', 'stripe_pk_live', 'stripe_sk_live', 'stripe_whsec_live',
            'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name', 'notify_emails',
            'notify_order', 'notify_form', 'notify_stock', 'notify_review',
        ];
        $checks = ['favicon_from_logo', 'logo_show_name', 'lang_toggle', 'ask_lang', 'whatsapp_float', 'social_instagram_on', 'social_facebook_on', 'social_tiktok_on', 'social_pinterest_on', 'social_youtube_on', 'stripe_enabled', 'notify_order', 'notify_form', 'notify_stock', 'notify_review'];
        $secrets = ['stripe_sk_test', 'stripe_sk_live', 'stripe_whsec_test', 'stripe_whsec_live', 'smtp_pass'];
        save_settings($keys, $checks, $secrets);
        foreach (['social_instagram', 'social_facebook', 'social_tiktok', 'social_pinterest', 'social_youtube'] as $k) {
            $v = (string)setting($k, '');
            if ($v !== '' && !preg_match('#^https?://#', $v)) set_setting($k, 'https://' . ltrim($v, '/'));
        }
        if ($v = rtrim((string)setting('site_url', ''), '/')) set_setting('site_url', $v);
        flash('Ajustes guardados.');
        redirect('/admin/settings' . (input('_section') ? '#' . preg_replace('/[^a-z-]/', '', (string)input('_section')) : ''));
    }
    $dbInfo = [
        'host' => $GLOBALS['CONFIG']['db_host'] ?? '', 'name' => $GLOBALS['CONFIG']['db_name'] ?? '',
        'products' => (int)val('SELECT COUNT(*) FROM products'), 'orders' => (int)val('SELECT COUNT(*) FROM orders'),
        'size' => (float)val('SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE()'),
        'version' => (string)val('SELECT VERSION()'),
    ];
    admin_page('Ajustes', 'settings', render('admin/settings', compact('dbInfo')));
}

function admin_seo(): void
{
    if (is_post()) {
        $keys = ['seo_title_en', 'seo_title_es', 'seo_desc_en', 'seo_desc_es', 'seo_keywords_en', 'seo_keywords_es', 'seo_title_template',
            'og_mode', 'og_image', 'og_bg', 'og_title_en', 'og_title_es', 'og_desc_en', 'og_desc_es', 'twitter_card',
            'biz_country', 'biz_price_range', 'ga_id', 'pixel_id', 'gsc_verify', 'seo_sitemap', 'seo_index', 'seo_hreflang', 'site_url'];
        save_settings($keys, ['seo_sitemap', 'seo_index', 'seo_hreflang']);
        foreach ((array)($_POST['page_seo'] ?? []) as $slug => $s) {
            $page = row('SELECT * FROM pages WHERE slug = ?', [(string)$slug]);
            if (!$page) continue;
            $seo = [];
            foreach (['title_en', 'title_es', 'desc_en', 'desc_es'] as $k) $seo[$k] = mb_substr((string)($s[$k] ?? ''), 0, 300);
            update('pages', ['seo' => jenc($seo)], 'id = ?', [$page['id']]);
        }
        flash('SEO guardado.');
        redirect('/admin/seo');
    }
    $pages = rows('SELECT * FROM pages ORDER BY FIELD(slug, "home", "shop", "contact"), id');
    admin_page('SEO y Open Graph', 'seo', render('admin/seo', compact('pages')));
}

function api_smtp_test(): void
{
    $to = trim((string)input('to')) ?: (string)setting('notify_emails', '');
    $err = null;
    $ok = send_mail($to, 'Prueba de correo — Sofi Love & Faith', '<div style="font-family:Arial,sans-serif"><h2 style="color:#B83A6B">¡Funciona!</h2><p>Tu servidor SMTP está bien configurado. Las notificaciones del sitio llegarán a este correo.</p></div>', '', $err);
    json_out($ok ? ['ok' => true, 'message' => 'Correo de prueba enviado a ' . $to] : ['ok' => false, 'message' => $err ?: 'No se pudo enviar.']);
}

function api_stripe_test(): void
{
    if (!str_starts_with(stripe_secret(), 'sk_')) json_out(['ok' => false, 'message' => 'Falta la clave secreta del modo ' . (stripe_mode() === 'live' ? 'real' : 'prueba') . '.']);
    try {
        $acc = stripe_request('GET', 'balance');
        json_out(['ok' => true, 'message' => 'Conexión correcta con Stripe (modo ' . (($acc['livemode'] ?? false) ? 'real' : 'prueba') . ').']);
    } catch (Throwable $ex) {
        json_out(['ok' => false, 'message' => $ex->getMessage()]);
    }
}

/* ---------- Backup ---------- */

function admin_backup(): void
{
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="sofi-respaldo-' . date('Y-m-d-His') . '.sql"');
    echo "-- Respaldo Sofi Love & Faith " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $create = db()->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1];
        echo "DROP TABLE IF EXISTS `$t`;\n$create;\n";
        $st = db()->query('SELECT * FROM `' . $t . '`');
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : db()->quote((string)$v), array_values($r));
            echo 'INSERT INTO `' . $t . '` VALUES (' . implode(',', $vals) . ");\n";
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}
