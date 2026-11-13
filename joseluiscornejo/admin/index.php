<?php
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/mailer.php';

header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-LiteSpeed-Cache-Control: no-cache');
header('X-Robots-Tag: noindex');

$sec = $_GET['s'] ?? 'inicio';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$admin = fn(string $q = '') => url('admin/' . ($q ? '?' . $q : ''));
function back(string $q): void { header('Location: ' . url('admin/?' . $q)); exit; }

// ---------- Login ----------
if ($sec === 'salir') {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . url('admin/'));
    exit;
}

if (empty($_SESSION['admin'])) {
    $error = '';
    if ($isPost) {
        csrf_check();
        $tries = $_SESSION['login_tries'] ?? 0;
        if ($tries >= 5 && time() - ($_SESSION['login_last'] ?? 0) < 300) {
            $error = 'Demasiados intentos. Espera 5 minutos.';
        } elseif (password_verify($_POST['password'] ?? '', s('admin_password'))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['login_tries'] = 0;
            back('s=inicio');
        } else {
            $_SESSION['login_tries'] = $tries + 1;
            $_SESSION['login_last'] = time();
            sleep(1);
            $error = 'Contraseña incorrecta.';
        }
    }
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Acceso · Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800&family=Inter:wght@400;600;700&family=Allura&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('admin/admin.css')) ?>?v=1"></head>
    <body class="login-body">
    <form class="login" method="post">
      <?= csrf_field() ?>
      <span class="login-name"><?= e(s('site_name')) ?></span>
      <span class="login-k">PANEL DE ADMINISTRACIÓN</span>
      <?php if ($error): ?><div class="alert alert-err"><?= e($error) ?></div><?php endif; ?>
      <label for="pw">Contraseña</label>
      <input id="pw" class="in in-dark" type="password" name="password" required autofocus autocomplete="current-password">
      <button class="b b-red" type="submit">Entrar</button>
    </form>
    </body></html><?php
    exit;
}

// ---------- Esquema de campos editables por página ----------
$pages = [
    'general' => ['General y redes', [
        ['site_name', 'Nombre del sitio', 'text'],
        ['site_tagline', 'Texto bajo el nombre', 'text'],
        ['logo', 'Logotipo (PNG transparente; si está vacío se usa el nombre en letra script)', 'image'],
        ['meta_description', 'Descripción para Google', 'textarea'],
        ['whatsapp', 'WhatsApp Business (solo números con código de país)', 'text'],
        ['whatsapp_label', 'Texto del botón de WhatsApp', 'text'],
        ['facebook_url', 'Facebook (página de creador) — enlace. Deja vacío un enlace para ocultar ese botón', 'url'],
        ['facebook_label', 'Facebook — texto', 'text'],
        ['instagram_url', 'Instagram — enlace', 'url'],
        ['instagram_label', 'Instagram — texto', 'text'],
        ['youtube_url', 'YouTube — enlace del canal', 'url'],
        ['youtube_label', 'YouTube — texto', 'text'],
        ['amazon_url', 'Amazon — enlace (botón de redes arriba y abajo)', 'url'],
        ['footer_note', 'Nota del pie de página', 'text'],
    ]],
    'inicio' => ['Inicio', [
        ['hero_badge', 'Etiqueta superior', 'text'],
        ['hero_title', 'Título (negro)', 'text'],
        ['hero_title_red', 'Título (rojo)', 'text'],
        ['hero_text', 'Texto principal', 'textarea'],
        ['hero_tagline', 'Frase en letra script', 'text'],
        ['hero_button', 'Texto del botón de compra', 'text'],
        ['hero_note', 'Nota bajo los botones', 'text'],
        ['hero_image', 'Imagen principal (portada)', 'image'],
        ['hero_bg', 'Imagen de fondo del banner (se ve tenue detrás del texto)', 'image'],
        ['hero_bg_opacity', 'Opacidad del fondo', 'range', ['min' => 0, 'max' => 1, 'step' => 0.01]],
        ['hero_bg_blur', 'Desenfoque del fondo (px)', 'range', ['min' => 0, 'max' => 40, 'step' => 1]],
        ['quote', 'Cita destacada', 'textarea'],
        ['about_kicker', 'Quién soy — etiqueta', 'text'],
        ['about_title', 'Quién soy — título', 'text'],
        ['about_subtitle', 'Quién soy — subtítulo', 'text'],
        ['about_text', 'Quién soy — texto', 'rich'],
        ['about_image', 'Quién soy — foto', 'image'],
    ]],
    'libros' => ['Libros', [
        ['books_title', 'Título de la sección', 'text'],
        ['books_text', 'Texto de la sección', 'textarea'],
    ]],
    'facilitacion' => ['Facilitación', [
        ['fac_title', 'Título', 'text'],
        ['fac_text', 'Texto', 'rich'],
        ['fac_c1_title', 'Tarjeta 1 — título', 'text'], ['fac_c1_text', 'Tarjeta 1 — texto', 'textarea'],
        ['fac_c2_title', 'Tarjeta 2 — título', 'text'], ['fac_c2_text', 'Tarjeta 2 — texto', 'textarea'],
        ['fac_c3_title', 'Tarjeta 3 — título', 'text'], ['fac_c3_text', 'Tarjeta 3 — texto', 'textarea'],
        ['talk_show', 'Plática', 'toggle'],
        ['talk_kicker', 'Plática — etiqueta', 'text'],
        ['talk_title', 'Plática — título', 'text'],
        ['talk_text', 'Plática — texto', 'textarea'],
        ['talk_button', 'Plática — texto del botón (abre WhatsApp)', 'text'],
    ]],
    'escritos' => ['Escritos y videos', [
        ['posts_title', 'Título de la página', 'text'],
        ['posts_text', 'Texto de la página', 'textarea'],
    ]],
    'contacto' => ['Contacto', [
        ['contact_title', 'Título', 'text'],
        ['contact_text', 'Texto', 'textarea'],
    ]],
];

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Grupo de cada campo en el editor visual.
function field_group(string $k): string {
    $map = [
        'hero_bg' => 'Fondo del banner', 'hero_' => 'Banner principal', 'quote' => 'Cita destacada', 'about_' => 'Quién soy',
        'fac_c' => 'Tarjetas', 'fac_' => 'Encabezado', 'talk_' => 'Plática gratuita',
        'site_' => 'Identidad', 'logo' => 'Identidad', 'meta_' => 'Identidad', 'whatsapp' => 'WhatsApp Business',
        'facebook' => 'Redes sociales', 'instagram' => 'Redes sociales', 'youtube' => 'Redes sociales', 'amazon' => 'Redes sociales',
        'footer' => 'Pie de página', 'books_' => 'Encabezado', 'posts_' => 'Encabezado', 'contact_' => 'Encabezado',
    ];
    foreach ($map as $prefix => $g) if (str_starts_with($k, $prefix)) return $g;
    return 'General';
}

$secretKeys = ['stripe_sk', 'stripe_whsec', 'paypal_secret', 'smtp_pass'];

// ---------- Acciones ----------
if ($isPost) {
    csrf_check();
    $do = $_POST['do'] ?? '';
    try {
        switch ($do) {
            case 'save_page':
                $g = $_POST['g'] ?? '';
                if (!isset($pages[$g])) break;
                $images = [];
                foreach ($pages[$g][1] as $fdef) {
                    [$k, , $type] = $fdef;
                    if ($type === 'image') {
                        if ($up = handle_upload('f_' . $k)) { set_setting($k, $up); $images[$k] = asset($up); }
                        elseif (!empty($_POST['clear_' . $k])) { set_setting($k, ''); $images[$k] = ''; }
                    } elseif (array_key_exists($k, $_POST)) {
                        set_setting($k, trim((string)$_POST[$k]));
                    }
                }
                if ($isAjax) json_out(['ok' => true, 'images' => $images, 'saved_at' => date('H:i')]);
                flash('Cambios publicados.');
                back('s=paginas&g=' . $g);

            case 'save_settings':
                $allowed = ['pay_gateway', 'currency', 'shipping', 'stripe_pk', 'stripe_sk', 'stripe_whsec', 'paypal_mode', 'paypal_client', 'paypal_secret',
                    'notify_email', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'smtp_from_name'];
                foreach ($allowed as $k) {
                    if (!array_key_exists($k, $_POST)) continue;
                    $v = trim((string)$_POST[$k]);
                    if (in_array($k, $secretKeys, true) && $v === '') continue; // vacío = no cambiar
                    if ($k === 'shipping') $v = number_format((float)$v, 2, '.', '');
                    if ($k === 'pay_gateway' && !in_array($v, ['stripe', 'paypal'], true)) $v = 'stripe';
                    set_setting($k, $v);
                }
                flash('Ajustes guardados.');
                back('s=' . ($_POST['back'] ?? 'pagos'));

            case 'test_mail':
                $err = null;
                $ok = send_mail(s('notify_email'), 'Correo de prueba', email_template('Correo de prueba', '<p>Si lees esto, las notificaciones del sitio funcionan.</p>'), null, $err);
                flash($ok ? 'Correo de prueba enviado a ' . s('notify_email') . '.' : 'No se pudo enviar: ' . $err, $ok ? 'ok' : 'error');
                back('s=correo');

            case 'password':
                if (!password_verify($_POST['old'] ?? '', s('admin_password'))) { flash('La contraseña actual no es correcta.', 'error'); back('s=contrasena'); }
                $new = (string)($_POST['new'] ?? '');
                if (strlen($new) < 8 || $new !== ($_POST['new2'] ?? '')) { flash('La nueva contraseña debe tener al menos 8 caracteres y coincidir.', 'error'); back('s=contrasena'); }
                set_setting('admin_password', password_hash($new, PASSWORD_DEFAULT));
                flash('Contraseña actualizada.');
                back('s=contrasena');

            case 'save_product':
                $id = (int)($_POST['id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                if ($title === '') { flash('El título es obligatorio.', 'error'); back('s=producto&id=' . $id); }
                $signed = trim($_POST['price_signed'] ?? '');
                $data = [
                    $title, trim($_POST['description'] ?? ''), (float)($_POST['price'] ?? 0),
                    $signed === '' ? null : (float)$signed, trim($_POST['amazon_url'] ?? ''),
                    in_array($_POST['status'] ?? '', ['disponible', 'proximamente', 'oculto'], true) ? $_POST['status'] : 'disponible',
                    (int)($_POST['sort'] ?? 0),
                ];
                if ($id) {
                    db()->prepare('UPDATE products SET title=?, description=?, price=?, price_signed=?, amazon_url=?, status=?, sort=? WHERE id=?')->execute([...$data, $id]);
                } else {
                    $slug = slugify($title);
                    $chk = db()->prepare('SELECT COUNT(*) FROM products WHERE slug = ?'); $chk->execute([$slug]);
                    if ($chk->fetchColumn()) $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
                    db()->prepare('INSERT INTO products (title, description, price, price_signed, amazon_url, status, sort, slug) VALUES (?,?,?,?,?,?,?,?)')->execute([...$data, $slug]);
                    $id = (int)db()->lastInsertId();
                }
                if ($up = handle_upload('image')) db()->prepare('UPDATE products SET image=? WHERE id=?')->execute([$up, $id]);
                flash('Producto guardado.');
                back('s=productos');

            case 'delete_product':
                db()->prepare('DELETE FROM products WHERE id=?')->execute([(int)$_POST['id']]);
                flash('Producto eliminado.');
                back('s=productos');

            case 'save_post':
                $id = (int)($_POST['id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $type = in_array($_POST['type'] ?? '', ['escrito', 'video', 'frase'], true) ? $_POST['type'] : 'escrito';
                $yt = trim($_POST['youtube_url'] ?? '');
                if ($type === 'video') {
                    if (youtube_id($yt) === '') { flash('Pega un enlace válido de YouTube (video o Short).', 'error'); back($id ? 's=escrito&id=' . $id : 's=escritos'); }
                    if ($title === '') $title = youtube_title($yt) ?: 'Video';
                    $_POST['published'] = $_POST['published'] ?? (empty($_POST['quick']) ? '' : '1');
                }
                if ($title === '') { flash('El título es obligatorio.', 'error'); back('s=escrito&id=' . $id); }
                $data = [$type, $title, trim($_POST['excerpt'] ?? ''), (string)($_POST['body'] ?? ''), $yt, empty($_POST['published']) ? 0 : 1];
                if ($id) {
                    db()->prepare('UPDATE posts SET type=?, title=?, excerpt=?, body=?, youtube_url=?, published=? WHERE id=?')->execute([...$data, $id]);
                } else {
                    $slug = slugify($title);
                    $chk = db()->prepare('SELECT COUNT(*) FROM posts WHERE slug = ?'); $chk->execute([$slug]);
                    if ($chk->fetchColumn()) $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
                    db()->prepare('INSERT INTO posts (type, title, excerpt, body, youtube_url, published, slug) VALUES (?,?,?,?,?,?,?)')->execute([...$data, $slug]);
                }
                flash('Publicación guardada.');
                back('s=escritos');

            case 'delete_post':
                db()->prepare('DELETE FROM posts WHERE id=?')->execute([(int)$_POST['id']]);
                flash('Publicación eliminada.');
                back('s=escritos');

            case 'order_status':
                $st = $_POST['status'] ?? '';
                if (in_array($st, ['pendiente', 'pagado', 'enviado', 'cancelado'], true)) {
                    db()->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$st, (int)$_POST['id']]);
                    if ($st === 'enviado' && !empty($_POST['notify'])) {
                        $o = db()->prepare('SELECT * FROM orders WHERE id=?'); $o->execute([(int)$_POST['id']]); $o = $o->fetch();
                        if ($o) send_mail($o['email'], 'Tu pedido ' . $o['code'] . ' va en camino', email_template('¡Tu pedido va en camino!', '<p>Hola ' . e($o['name']) . ', tu pedido <b>' . e($o['code']) . '</b> ya fue enviado.</p>'));
                    }
                }
                flash('Pedido actualizado.');
                back('s=pedidos');

            case 'message_read':
                db()->prepare('UPDATE messages SET is_read=1 WHERE id=?')->execute([(int)$_POST['id']]);
                back('s=mensajes');

            case 'delete_message':
                db()->prepare('DELETE FROM messages WHERE id=?')->execute([(int)$_POST['id']]);
                back('s=mensajes');
        }
    } catch (Throwable $ex) {
        if ($isAjax) json_out(['ok' => false, 'error' => $ex->getMessage()], 400);
        flash($ex->getMessage(), 'error');
        back('s=' . $sec);
    }
}

if ($sec === 'exportar') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="suscriptores.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['correo', 'origen', 'fecha']);
    foreach (db()->query('SELECT email, source, created_at FROM subscribers ORDER BY id DESC') as $r) fputcsv($out, $r);
    exit;
}

// ---------- Vista ----------
$menu = [
    'inicio' => 'Resumen', 'paginas' => 'Páginas', 'escritos' => 'Escritos y videos', 'productos' => 'Productos',
    'pedidos' => 'Pedidos', 'mensajes' => 'Mensajes', 'suscriptores' => 'Suscriptores',
    'pagos' => 'Pagos', 'correo' => 'Correo SMTP', 'contrasena' => 'Contraseña',
];
$activeMenu = ['pagina' => 'paginas', 'producto' => 'productos', 'escrito' => 'escritos'][$sec] ?? $sec;
$unread = (int)db()->query('SELECT COUNT(*) FROM messages WHERE is_read = 0')->fetchColumn();
$f = flash();
$needsEditor = in_array($sec, ['paginas', 'escrito'], true);

function field(string $name, string $label, string $value = '', string $type = 'text', string $extra = ''): void {
    $id = 'f-' . $name;
    echo '<div class="fld"><label for="' . e($id) . '">' . e($label) . '</label>';
    if ($type === 'textarea') echo '<textarea class="in" id="' . e($id) . '" name="' . e($name) . '" rows="3" ' . $extra . '>' . e($value) . '</textarea>';
    else echo '<input class="in" id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" ' . $extra . '>';
    echo '</div>';
}
function secret_field(string $name, string $label): void {
    field($name, $label, '', 'password', 'autocomplete="new-password" placeholder="' . (s($name) !== '' ? '•••••• guardada (deja vacío para no cambiar)' : 'Sin configurar') . '"');
}
function rich_field(string $name, string $label, string $value): void {
    $tools = [
        ['undo', 'Deshacer', '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>'],
        ['redo', 'Rehacer', '<path d="m15 14 5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h3"/>'],
        ['|'],
        ['h2', 'Título', '<text x="3" y="17" font-size="13" font-weight="700" fill="currentColor" stroke="none" font-family="Inter,sans-serif">H2</text>'],
        ['h3', 'Subtítulo', '<text x="3" y="17" font-size="13" font-weight="700" fill="currentColor" stroke="none" font-family="Inter,sans-serif">H3</text>'],
        ['|'],
        ['bold', 'Negrita', '<path d="M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z"/>'],
        ['italic', 'Cursiva', '<path d="M14 5h-4M14 19h-4M14 5l-4 14"/>'],
        ['underline', 'Subrayado', '<path d="M7 4v7a5 5 0 0 0 10 0V4M5 20h14"/>'],
        ['link', 'Enlace', '<path d="M10 14a4 4 0 0 0 6 0l3-3a4 4 0 0 0-6-6l-1 1"/><path d="M14 10a4 4 0 0 0-6 0l-3 3a4 4 0 0 0 6 6l1-1"/>'],
        ['|'],
        ['bulletList', 'Lista', '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1" fill="currentColor"/><circle cx="4.5" cy="12" r="1" fill="currentColor"/><circle cx="4.5" cy="18" r="1" fill="currentColor"/>'],
        ['orderedList', 'Lista numerada', '<path d="M10 6h10M10 12h10M10 18h10M4 6h1v4M4 10h2M4 14.5a1 1 0 1 1 2 .5L4 18h2"/>'],
        ['blockquote', 'Cita', '<path d="M7 7h4v4H8v2a2 2 0 0 0 2 2M15 7h4v4h-3v2a2 2 0 0 0 2 2"/>'],
        ['|'],
        ['left', 'Alinear a la izquierda', '<path d="M4 6h16M4 10h10M4 14h16M4 18h10"/>'],
        ['center', 'Centrar', '<path d="M4 6h16M7 10h10M4 14h16M7 18h10"/>'],
        ['clear', 'Quitar formato', '<path d="M5 5h14M10 5l-3 14M14 5l1 5M4 20 20 4"/>'],
    ];
    echo '<div class="fld" data-key="' . e($name) . '"><span class="lbl">' . e($label) . '</span><div class="tt">';
    echo '<div class="tt-bar" role="toolbar" aria-label="Formato de ' . e($label) . '">';
    foreach ($tools as $t) {
        if ($t[0] === '|') { echo '<span class="tt-sep"></span>'; continue; }
        echo '<button type="button" class="tt-btn" data-cmd="' . $t[0] . '" title="' . e($t[1]) . '" aria-label="' . e($t[1]) . '"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $t[2] . '</svg></button>';
    }
    echo '</div><div class="tt-ed" data-tt></div><div class="tt-foot"><span data-tt-count></span></div></div>';
    echo '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '" data-tt-input></div>';
}

function studio_field(array $fdef): void {
    [$k, $label, $type] = $fdef;
    $opt = $fdef[3] ?? [];
    $val = s($k);
    if ($type === 'rich') { rich_field($k, $label, $val); return; }
    echo '<div class="fld" data-key="' . e($k) . '">';
    if ($type === 'toggle') {
        echo '<input type="hidden" name="' . e($k) . '" value="0"><label class="switch"><input type="checkbox" name="' . e($k) . '" value="1" data-live ' . ($val === '1' ? 'checked' : '') . '><span class="sw"></span><span>' . e($label === 'Plática' ? 'Mostrar la sección de plática gratuita' : $label) . '</span></label>';
    } elseif ($type === 'image') {
        $removable = in_array($k, ['logo', 'hero_bg'], true);
        echo '<span class="lbl">' . e($label) . '</span><label class="drop' . ($val ? ' has' : '') . '">'
            . '<img data-thumb src="' . e($val ? asset($val) : '') . '" alt="">'
            . '<span class="drop-txt"><b>Cambiar imagen</b><small>Arrastra aquí o haz clic · JPG, PNG o WEBP</small></span>'
            . '<input type="file" name="f_' . e($k) . '" accept="image/*" data-live></label>';
        if ($removable) echo '<label class="chk mini-chk"><input type="checkbox" name="clear_' . e($k) . '" value="1" data-clear> Quitar imagen</label>';
    } elseif ($type === 'range') {
        echo '<label for="f-' . e($k) . '">' . e($label) . ' <output data-out>' . e($val) . '</output></label>'
            . '<input class="range" id="f-' . e($k) . '" type="range" name="' . e($k) . '" value="' . e($val) . '" min="' . $opt['min'] . '" max="' . $opt['max'] . '" step="' . $opt['step'] . '" data-live>';
    } else {
        $id = 'f-' . $k;
        echo '<label for="' . e($id) . '">' . e($label) . '</label>';
        if ($type === 'textarea') echo '<textarea class="in" id="' . e($id) . '" name="' . e($k) . '" rows="3" data-live data-autosize>' . e($val) . '</textarea>';
        else echo '<input class="in" id="' . e($id) . '" name="' . e($k) . '" type="' . ($type === 'url' ? 'url' : 'text') . '" value="' . e($val) . '" data-live>';
    }
    echo '</div>';
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($menu[$activeMenu] ?? 'Panel') ?> · Panel</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800&family=Inter:wght@400;500;600;700&family=Allura&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('admin/admin.css')) ?>?v=2">
</head>
<body class="<?= $activeMenu === 'paginas' ? 'is-studio' : '' ?>">
<div class="shell">
  <aside class="side">
    <span class="side-name"><?= e(s('site_name')) ?></span>
    <span class="side-k">SUPER ADMIN · v<?= APP_VERSION ?></span>
    <nav>
      <?php foreach ($menu as $k => $label): ?>
        <a class="<?= $activeMenu === $k ? 'on' : '' ?>" href="<?= e($admin('s=' . $k)) ?>"><?= e($label) ?><?php if ($k === 'mensajes' && $unread): ?> <span class="dot"><?= $unread ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <a href="<?= e(url('')) ?>" target="_blank">Ver sitio →</a>
      <a href="<?= e($admin('s=salir')) ?>">Cerrar sesión</a>
    </div>
  </aside>
  <main class="main">
    <?php if ($f): ?><div class="alert <?= $f['t'] === 'error' ? 'alert-err' : '' ?>"><?= e($f['m']) ?></div><?php endif; ?>

<?php switch ($activeMenu):
case 'inicio':
    $paid = db()->query("SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM orders WHERE status IN ('pagado','enviado')")->fetch();
    $pend = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pagado'")->fetchColumn();
    $subs = (int)db()->query('SELECT COUNT(*) FROM subscribers')->fetchColumn();
    $last = db()->query("SELECT * FROM orders WHERE status <> 'pendiente' ORDER BY id DESC LIMIT 5")->fetchAll(); ?>
    <h1>Resumen</h1>
    <section class="hero-card">
      <div class="hero-card-txt">
        <span class="hc-k">NUEVO · EDITOR VISUAL v<?= APP_VERSION ?></span>
        <h2>Edita tu sitio viéndolo en vivo</h2>
        <p>Haz clic en cualquier texto de la página, cámbialo y publícalo. Las fotos, el fondo del banner y los textos con formato se editan igual.</p>
      </div>
      <div class="hc-pages">
        <?php foreach ($pages as $k => [$label]): ?><a class="hc-page" href="<?= e($admin('s=paginas&g=' . $k)) ?>"><span><?= e($label) ?></span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></a><?php endforeach; ?>
      </div>
    </section>
    <div class="quick">
      <a class="qk" href="<?= e($admin('s=escritos')) ?>"><b>+ Video de YouTube</b><span>Pega el enlace y listo</span></a>
      <a class="qk" href="<?= e($admin('s=escrito&tipo=escrito')) ?>"><b>+ Nuevo escrito</b><span>Poema o reflexión</span></a>
      <a class="qk" href="<?= e($admin('s=productos')) ?>"><b>Precios y libros</b><span>Estándar y firmado</span></a>
      <a class="qk" href="<?= e($admin('s=pedidos')) ?>"><b>Pedidos</b><span>Ventas y envíos</span></a>
    </div>
    <div class="stats">
      <div class="stat"><span>Ventas</span><b><?= money($paid['t']) ?></b></div>
      <div class="stat"><span>Pedidos pagados</span><b><?= (int)$paid['c'] ?></b></div>
      <div class="stat"><span>Por enviar</span><b><?= $pend ?></b></div>
      <div class="stat"><span>Mensajes sin leer</span><b><?= $unread ?></b></div>
      <div class="stat"><span>Suscriptores</span><b><?= $subs ?></b></div>
    </div>
    <?php if (s('pay_gateway') === 'stripe' ? s('stripe_sk') === '' : s('paypal_client') === ''): ?>
      <div class="alert alert-warn">Falta configurar la pasarela de pago. Ve a <a href="<?= e($admin('s=pagos')) ?>">Pagos</a>.</div>
    <?php endif; ?>
    <?php if (s('smtp_pass') === ''): ?>
      <div class="alert alert-warn">Falta la contraseña del correo SMTP para enviar notificaciones. Ve a <a href="<?= e($admin('s=correo')) ?>">Correo SMTP</a>.</div>
    <?php endif; ?>
    <?php if (password_verify('admin123', s('admin_password'))): ?>
      <div class="alert alert-warn">Sigues usando la contraseña inicial. <a href="<?= e($admin('s=contrasena')) ?>">Cámbiala aquí</a>.</div>
    <?php endif; ?>
    <h2>Últimos pedidos</h2>
    <?php if (!$last): ?><p class="mute">Aún no hay pedidos.</p><?php else: ?>
      <div class="box tbl"><table><thead><tr><th>Pedido</th><th>Cliente</th><th>Total</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>
      <?php foreach ($last as $o): ?><tr><td><?= e($o['code']) ?></td><td><?= e($o['name']) ?></td><td><?= money($o['total']) ?></td><td><span class="st st-<?= e($o['status']) ?>"><?= e($o['status']) ?></span></td><td><?= e(date('d/m/Y', strtotime($o['created_at']))) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
<?php break;

case 'paginas':
    $g = isset($pages[$_GET['g'] ?? '']) ? $_GET['g'] : 'inicio';
    $routes = ['general' => '', 'inicio' => '', 'libros' => 'libros', 'facilitacion' => 'facilitacion', 'escritos' => 'escritos', 'contacto' => 'contacto'];
    $r = $routes[$g];
    $previewUrl = url($r === '' ? '?preview=1' : $r . '?preview=1');
    $groups = [];
    foreach ($pages[$g][1] as $fdef) $groups[field_group($fdef[0])][] = $fdef; ?>
    <div class="studio" data-studio>
      <header class="st-top">
        <a class="st-back" href="<?= e($admin('s=inicio')) ?>" aria-label="Volver al panel" title="Volver al panel"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg></a>
        <div class="st-title"><span class="st-k">Editor visual</span><b><?= e($pages[$g][0]) ?></b></div>
        <nav class="st-pages" aria-label="Páginas">
          <?php foreach ($pages as $k => [$label]): ?><a class="<?= $k === $g ? 'on' : '' ?>" href="<?= e($admin('s=paginas&g=' . $k)) ?>" data-nav><?= e($label) ?></a><?php endforeach; ?>
        </nav>
        <div class="st-actions">
          <div class="seg" role="group" aria-label="Tamaño de la vista previa">
            <button type="button" class="on" data-device="100%" aria-label="Computadora" title="Computadora"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg></button>
            <button type="button" data-device="820px" aria-label="Tableta" title="Tableta"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M11 18h2"/></svg></button>
            <button type="button" data-device="390px" aria-label="Celular" title="Celular"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/></svg></button>
          </div>
          <span class="st-status" data-status aria-live="polite"><i></i><span>Todo publicado</span></span>
          <button type="button" class="b b-red st-save" data-save disabled>Publicar</button>
        </div>
      </header>
      <div class="st-switch" role="tablist">
        <button type="button" class="on" data-view="edit">Editar</button>
        <button type="button" data-view="preview">Vista previa</button>
      </div>
      <div class="st-body" data-body>
        <form class="st-panel" data-form method="post" enctype="multipart/form-data" novalidate>
          <?= csrf_field() ?><input type="hidden" name="do" value="save_page"><input type="hidden" name="g" value="<?= e($g) ?>">
          <p class="st-hint"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 4l7 17 2-7 7-2z"/></svg> Haz clic en cualquier texto de la vista previa para editarlo aquí.</p>
          <?php foreach ($groups as $gname => $fields): ?>
            <details class="grp" open>
              <summary><span><?= e($gname) ?></span><small><?= count($fields) ?></small></summary>
              <div class="grp-body"><?php foreach ($fields as $fdef) studio_field($fdef); ?></div>
            </details>
          <?php endforeach; ?>
        </form>
        <div class="st-stage">
          <div class="st-frame" data-frame-wrap>
            <div class="st-loading" data-loading><span></span></div>
            <iframe src="<?= e($previewUrl) ?>" title="Vista previa en vivo" data-frame></iframe>
          </div>
        </div>
      </div>
      <div class="toast" data-toast role="status"></div>
    </div>
<?php break;

case 'productos':
    if ($sec === 'producto'):
        $p = ['id' => 0, 'title' => '', 'description' => '', 'price' => '', 'price_signed' => '', 'amazon_url' => '', 'status' => 'disponible', 'sort' => 0, 'image' => ''];
        if (!empty($_GET['id'])) { $st = db()->prepare('SELECT * FROM products WHERE id=?'); $st->execute([(int)$_GET['id']]); $p = $st->fetch() ?: $p; } ?>
        <div class="head"><h1><?= $p['id'] ? 'Editar producto' : 'Nuevo producto' ?></h1><a class="b" href="<?= e($admin('s=productos')) ?>">← Volver</a></div>
        <form class="box form grid" method="post" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="do" value="save_product"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <div class="full"><?php field('title', 'Título (en videos, si lo dejas vacío se toma el de YouTube)', $p['title']); ?></div>
          <div class="full"><?php field('description', 'Descripción', (string)$p['description'], 'textarea'); ?></div>
          <?php field('price', 'Precio estándar (' . s('currency') . ')', (string)$p['price'], 'number', 'step="0.01" min="0"'); ?>
          <?php field('price_signed', 'Precio firmado y dedicado (vacío = no se ofrece)', (string)$p['price_signed'], 'number', 'step="0.01" min="0"'); ?>
          <?php field('amazon_url', 'Enlace de Amazon', (string)$p['amazon_url'], 'url'); ?>
          <div class="fld"><label for="f-status">Estado</label><select class="in" id="f-status" name="status">
            <?php foreach (['disponible' => 'Disponible (a la venta)', 'proximamente' => 'Próximamente', 'oculto' => 'Oculto'] as $k => $l): ?><option value="<?= $k ?>" <?= $p['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select></div>
          <?php field('sort', 'Orden', (string)$p['sort'], 'number'); ?>
          <div class="fld"><span class="lbl">Portada</span><div class="img-row"><?php if ($p['image']): ?><img class="thumb" src="<?= e(asset($p['image'])) ?>" alt=""><?php endif; ?><input type="file" name="image" accept="image/*" aria-label="Portada"></div></div>
          <div class="actions full"><button class="b b-red" type="submit">Guardar producto</button></div>
        </form>
    <?php else:
        $rows = db()->query('SELECT * FROM products ORDER BY sort, id')->fetchAll(); ?>
        <div class="head"><h1>Productos</h1><a class="b b-red" href="<?= e($admin('s=producto')) ?>">+ Nuevo producto</a></div>
        <div class="box tbl"><table><thead><tr><th></th><th>Producto</th><th>Estándar</th><th>Firmado</th><th>Estado</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $p): ?>
          <tr><td><?php if ($p['image']): ?><img class="mini" src="<?= e(asset($p['image'])) ?>" alt=""><?php endif; ?></td>
            <td><b><?= e($p['title']) ?></b></td><td><?= money($p['price']) ?></td><td><?= $p['price_signed'] !== null ? money($p['price_signed']) : '—' ?></td>
            <td><span class="st st-<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
            <td class="r"><a class="b b-sm" href="<?= e($admin('s=producto&id=' . $p['id'])) ?>">Editar</a>
              <form method="post" class="inline" onsubmit="return confirm('¿Eliminar este producto?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete_product"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="b b-sm b-del" type="submit">Eliminar</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif;
break;

case 'escritos':
    if ($sec === 'escrito'):
        $p = ['id' => 0, 'type' => $_GET['tipo'] ?? 'escrito', 'title' => '', 'excerpt' => '', 'body' => '', 'youtube_url' => '', 'published' => 1];
        if (!empty($_GET['id'])) { $st = db()->prepare('SELECT * FROM posts WHERE id=?'); $st->execute([(int)$_GET['id']]); $p = $st->fetch() ?: $p; } ?>
        <div class="head"><h1><?= $p['id'] ? 'Editar publicación' : 'Nueva publicación' ?></h1><a class="b" href="<?= e($admin('s=escritos')) ?>">← Volver</a></div>
        <form class="box form" method="post" data-rich>
          <?= csrf_field() ?><input type="hidden" name="do" value="save_post"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <div class="fld"><label for="f-type">Tipo</label><select class="in" id="f-type" name="type">
            <?php foreach (['escrito' => 'Escrito (poema o reflexión)', 'video' => 'Video de YouTube', 'frase' => 'Frase del día'] as $k => $l): ?><option value="<?= $k ?>" <?= $p['type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select></div>
          <?php field('title', 'Título (en videos, si lo dejas vacío se toma el de YouTube)', $p['title']); ?>
          <?php field('youtube_url', 'Enlace de YouTube (solo para videos; se inserta, no se sube)', (string)$p['youtube_url'], 'url', 'placeholder="https://www.youtube.com/watch?v=..."'); ?>
          <?php field('excerpt', 'Resumen / frase (se muestra en la tarjeta)', (string)$p['excerpt'], 'textarea'); ?>
          <?php rich_field('body', 'Texto completo', (string)$p['body']); ?>
          <label class="chk"><input type="checkbox" name="published" value="1" <?= $p['published'] ? 'checked' : '' ?>> Publicado</label>
          <div class="actions"><button class="b b-red" type="submit">Guardar</button></div>
        </form>
    <?php else:
        $rows = db()->query('SELECT * FROM posts ORDER BY created_at DESC, id DESC')->fetchAll(); ?>
        <div class="head"><h1>Escritos y videos</h1>
          <div class="row"><a class="b b-red" href="<?= e($admin('s=escrito&tipo=escrito')) ?>">+ Nuevo escrito</a><a class="b" href="<?= e($admin('s=escrito&tipo=video')) ?>">+ Video de YouTube</a><a class="b" href="<?= e($admin('s=escrito&tipo=frase')) ?>">+ Frase del día</a></div></div>
        <form class="box quick" method="post">
          <?= csrf_field() ?><input type="hidden" name="do" value="save_post"><input type="hidden" name="type" value="video"><input type="hidden" name="quick" value="1">
          <label for="q-yt"><b>Agregar video rápido:</b> pega el enlace de YouTube (video normal o Short)</label>
          <div class="row"><input class="in grow" id="q-yt" name="youtube_url" type="url" required placeholder="https://www.youtube.com/shorts/..."><button class="b b-red" type="submit">Agregar video</button></div>
        </form>
        <div class="box tbl"><table><thead><tr><th></th><th>Título</th><th>Tipo</th><th>Estado</th><th>Fecha</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $p): $yid = youtube_id((string)$p['youtube_url']); ?>
          <tr><td><?php if ($yid): ?><a href="<?= e($p['youtube_url']) ?>" target="_blank" rel="noopener"><img class="yt-thumb" src="https://i.ytimg.com/vi/<?= e($yid) ?>/mqdefault.jpg" alt="Miniatura"></a><?php endif; ?></td><td><b><?= e($p['title']) ?></b><?php if ($p['type'] === 'video' && !youtube_id((string)$p['youtube_url'])): ?> <span class="st st-pendiente">falta enlace</span><?php endif; ?></td><td><?= e($p['type']) ?></td>
            <td><?= $p['published'] ? 'Publicado' : 'Borrador' ?></td><td><?= e(date('d/m/Y', strtotime($p['created_at']))) ?></td>
            <td class="r"><a class="b b-sm" href="<?= e($admin('s=escrito&id=' . $p['id'])) ?>">Editar</a>
              <form method="post" class="inline" onsubmit="return confirm('¿Eliminar?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete_post"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="b b-sm b-del" type="submit">Eliminar</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif;
break;

case 'pedidos':
    $rows = db()->query('SELECT * FROM orders ORDER BY id DESC LIMIT 300')->fetchAll(); ?>
    <h1>Pedidos</h1>
    <?php if (!$rows): ?><p class="mute">Aún no hay pedidos.</p><?php endif; ?>
    <?php foreach ($rows as $o): $items = json_decode($o['items'], true) ?: []; ?>
      <div class="box order">
        <div class="order-h"><b><?= e($o['code']) ?></b><span class="st st-<?= e($o['status']) ?>"><?= e($o['status']) ?></span><span class="mute"><?= e(date('d/m/Y H:i', strtotime($o['created_at']))) ?> · <?= e($o['gateway']) ?></span><b class="ml"><?= money($o['total']) ?></b></div>
        <div class="order-b">
          <div><b><?= e($o['name']) ?></b><br><?= e($o['email']) ?><br><?= e($o['phone']) ?><br><?= nl2br(e($o['address'])) ?>
            <?php if ($o['dedication']): ?><br><b>Dedicatoria:</b> <?= nl2br(e($o['dedication'])) ?><?php endif; ?></div>
          <div><?php foreach ($items as $l): ?><?= e($l['title']) ?> — <?= e($l['edition']) ?> × <?= (int)$l['qty'] ?><br><?php endforeach; ?></div>
          <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="order_status"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
            <select class="in in-sm" name="status" aria-label="Estado"><?php foreach (['pendiente', 'pagado', 'enviado', 'cancelado'] as $st): ?><option <?= $o['status'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select>
            <label class="chk"><input type="checkbox" name="notify" value="1" checked> Avisar al cliente si se envía</label>
            <button class="b b-sm" type="submit">Guardar</button></form>
        </div>
      </div>
    <?php endforeach;
break;

case 'mensajes':
    $rows = db()->query('SELECT * FROM messages ORDER BY id DESC LIMIT 300')->fetchAll(); ?>
    <h1>Mensajes</h1>
    <?php if (!$rows): ?><p class="mute">Aún no hay mensajes.</p><?php endif; ?>
    <?php foreach ($rows as $m): ?>
      <div class="box msg <?= $m['is_read'] ? '' : 'unread' ?>">
        <div class="order-h"><b><?= e($m['name']) ?></b><a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a><span class="mute ml"><?= e(date('d/m/Y H:i', strtotime($m['created_at']))) ?></span></div>
        <p><?= nl2br(e($m['message'])) ?></p>
        <div class="row">
          <?php if (!$m['is_read']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="message_read"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="b b-sm" type="submit">Marcar como leído</button></form><?php endif; ?>
          <form method="post" class="inline" onsubmit="return confirm('¿Eliminar mensaje?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete_message"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="b b-sm b-del" type="submit">Eliminar</button></form>
        </div>
      </div>
    <?php endforeach;
break;

case 'suscriptores':
    $rows = db()->query('SELECT * FROM subscribers ORDER BY id DESC')->fetchAll(); ?>
    <div class="head"><h1>Suscriptores</h1><a class="b" href="<?= e($admin('s=exportar')) ?>">Descargar CSV</a></div>
    <div class="box tbl"><table><thead><tr><th>Correo</th><th>Origen</th><th>Fecha</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?= e($r['email']) ?></td><td><?= e($r['source']) ?></td><td><?= e(date('d/m/Y', strtotime($r['created_at']))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
<?php break;

case 'pagos': ?>
    <h1>Pagos</h1>
    <form class="form" method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="save_settings"><input type="hidden" name="back" value="pagos">
      <fieldset class="box">
        <legend>Pasarela activa para el botón «Comprar»</legend>
        <p class="mute">El cliente siempre ve un solo botón y paga con tarjeta. Aquí eliges qué pasarela cobra.</p>
        <div class="row">
          <label class="radio"><input type="radio" name="pay_gateway" value="stripe" <?= s('pay_gateway') !== 'paypal' ? 'checked' : '' ?>> Stripe</label>
          <label class="radio"><input type="radio" name="pay_gateway" value="paypal" <?= s('pay_gateway') === 'paypal' ? 'checked' : '' ?>> PayPal (tarjeta, sin cuenta PayPal)</label>
        </div>
        <div class="grid"><?php field('currency', 'Moneda (código ISO)', s('currency'), 'text', 'maxlength="3"'); ?><?php field('shipping', 'Costo de envío por pedido (0 = gratis)', s('shipping'), 'number', 'step="0.01" min="0"'); ?></div>
      </fieldset>
      <div class="grid">
        <fieldset class="box"><legend class="c-stripe">Stripe</legend>
          <?php field('stripe_pk', 'Clave publicable (pk_live_…)', s('stripe_pk')); ?>
          <?php secret_field('stripe_sk', 'Clave secreta (sk_live_…)'); ?>
          <?php secret_field('stripe_whsec', 'Secreto del webhook (whsec_…) — opcional'); ?>
          <p class="mute small">Webhook: <code><?= e(abs_url('webhook/stripe')) ?></code> · evento <code>checkout.session.completed</code></p>
        </fieldset>
        <fieldset class="box"><legend class="c-paypal">PayPal</legend>
          <div class="fld"><label for="f-pm">Modo</label><select class="in" id="f-pm" name="paypal_mode"><option value="live" <?= s('paypal_mode') !== 'sandbox' ? 'selected' : '' ?>>En vivo</option><option value="sandbox" <?= s('paypal_mode') === 'sandbox' ? 'selected' : '' ?>>Pruebas (sandbox)</option></select></div>
          <?php field('paypal_client', 'Client ID', s('paypal_client')); ?>
          <?php secret_field('paypal_secret', 'Secret'); ?>
        </fieldset>
      </div>
      <div class="actions"><button class="b b-red" type="submit">Guardar pagos</button></div>
    </form>
<?php break;

case 'correo': ?>
    <h1>Correo y notificaciones (SMTP)</h1>
    <form class="box form grid" method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="save_settings"><input type="hidden" name="back" value="correo">
      <div class="full"><?php field('notify_email', 'Enviar avisos de ventas, mensajes y suscriptores a', s('notify_email'), 'email'); ?></div>
      <?php field('smtp_host', 'Servidor SMTP', s('smtp_host')); ?>
      <?php field('smtp_port', 'Puerto', s('smtp_port'), 'number'); ?>
      <div class="fld"><label for="f-sec">Seguridad</label><select class="in" id="f-sec" name="smtp_secure"><option value="ssl" <?= s('smtp_secure') === 'ssl' ? 'selected' : '' ?>>SSL (puerto 465)</option><option value="tls" <?= s('smtp_secure') === 'tls' ? 'selected' : '' ?>>STARTTLS (puerto 587)</option></select></div>
      <?php field('smtp_from_name', 'Nombre del remitente', s('smtp_from_name')); ?>
      <?php field('smtp_user', 'Usuario (correo completo)', s('smtp_user'), 'email'); ?>
      <?php secret_field('smtp_pass', 'Contraseña del correo'); ?>
      <div class="actions full"><button class="b b-red" type="submit">Guardar</button></div>
    </form>
    <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="do" value="test_mail"><button class="b" type="submit">Enviar correo de prueba</button></form>
<?php break;

case 'contrasena': ?>
    <h1>Cambiar contraseña</h1>
    <form class="box form narrow" method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="password">
      <?php field('old', 'Contraseña actual', '', 'password', 'required autocomplete="current-password"'); ?>
      <?php field('new', 'Nueva contraseña (mínimo 8 caracteres)', '', 'password', 'required minlength="8" autocomplete="new-password"'); ?>
      <?php field('new2', 'Repite la nueva contraseña', '', 'password', 'required minlength="8" autocomplete="new-password"'); ?>
      <div class="actions"><button class="b b-red" type="submit">Actualizar</button></div>
    </form>
<?php break;

default: ?><h1>Sección no encontrada</h1><?php
endswitch; ?>
  </main>
</div>
<?php if ($needsEditor): ?>
<script src="<?= e(url('admin/vendor/tiptap.js')) ?>?v=1"></script>
<script src="<?= e(url('admin/editor.js')) ?>?v=1"></script>
<?php endif; ?>
</body>
</html>
