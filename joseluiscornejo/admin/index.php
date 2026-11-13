<?php
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/mailer.php';

header('X-Frame-Options: SAMEORIGIN');
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
        ['hero_title', 'Título (blanco)', 'text'],
        ['hero_title_red', 'Título (rojo)', 'text'],
        ['hero_text', 'Texto principal', 'textarea'],
        ['hero_tagline', 'Frase en letra script', 'text'],
        ['hero_button', 'Texto del botón de compra', 'text'],
        ['hero_note', 'Nota bajo los botones', 'text'],
        ['hero_image', 'Imagen principal (portada)', 'image'],
        ['hero_bg', 'Imagen de fondo del banner (se ve tenue detrás del texto)', 'image'],
        ['hero_bg_opacity', 'Opacidad del fondo: 0 = invisible, 0.4 = sugerido, 1 = completa', 'text'],
        ['hero_bg_blur', 'Desenfoque del fondo en píxeles (0 = nítido, 10 = sugerido)', 'text'],
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
                foreach ($pages[$g][1] as [$k, , $type]) {
                    if ($type === 'image') {
                        if ($up = handle_upload('f_' . $k)) set_setting($k, $up);
                        elseif (!empty($_POST['clear_' . $k])) set_setting($k, '');
                    } else {
                        set_setting($k, trim((string)($_POST[$k] ?? '')));
                    }
                }
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
    echo '<div class="fld"><span class="lbl">' . e($label) . '</span><div class="rich-wrap"><div class="rich-ed" data-target="' . e($name) . '">' . $value . '</div></div>'
        . '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '"></div>';
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($menu[$activeMenu] ?? 'Panel') ?> · Panel</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800&family=Inter:wght@400;500;600;700&family=Allura&display=swap" rel="stylesheet">
<?php if ($needsEditor): ?><link href="<?= e(url('admin/vendor/quill.snow.css')) ?>" rel="stylesheet"><?php endif; ?>
<link rel="stylesheet" href="<?= e(url('admin/admin.css')) ?>?v=1">
</head>
<body>
<div class="shell">
  <aside class="side">
    <span class="side-name"><?= e(s('site_name')) ?></span>
    <span class="side-k">SUPER ADMIN</span>
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
    $g = isset($pages[$_GET['g'] ?? '']) ? $_GET['g'] : 'inicio'; ?>
    <div class="head"><h1>Editar página · <?= e($pages[$g][0]) ?></h1></div>
    <div class="tabs">
      <?php foreach ($pages as $k => [$label]): ?><a class="<?= $k === $g ? 'on' : '' ?>" href="<?= e($admin('s=paginas&g=' . $k)) ?>"><?= e($label) ?></a><?php endforeach; ?>
    </div>
    <form class="box form" method="post" enctype="multipart/form-data" data-rich>
      <?= csrf_field() ?><input type="hidden" name="do" value="save_page"><input type="hidden" name="g" value="<?= e($g) ?>">
      <?php foreach ($pages[$g][1] as [$k, $label, $type]):
        if ($type === 'rich'):
          rich_field($k, $label, s($k));
        elseif ($type === 'toggle'): ?>
          <input type="hidden" name="<?= e($k) ?>" value="0"><label class="chk"><input type="checkbox" name="<?= e($k) ?>" value="1" <?= s($k) === '1' ? 'checked' : '' ?>> Mostrar la sección de plática gratuita</label>
        <?php
        elseif ($type === 'image'): ?>
          <div class="fld"><span class="lbl"><?= e($label) ?></span>
            <div class="img-row">
              <?php if (s($k)): ?><img src="<?= e(asset(s($k))) ?>" alt="" class="thumb"><?php endif; ?>
              <input type="file" name="f_<?= e($k) ?>" accept="image/*" aria-label="<?= e($label) ?>">
              <?php if (s($k) && in_array($k, ['logo', 'hero_bg'], true)): ?><label class="chk"><input type="checkbox" name="clear_<?= e($k) ?>" value="1"> Quitar</label><?php endif; ?>
            </div>
          </div>
        <?php else: field($k, $label, s($k), $type === 'textarea' ? 'textarea' : ($type === 'url' ? 'url' : 'text'));
        endif;
      endforeach; ?>
      <div class="actions"><button class="b b-red" type="submit">Publicar cambios</button><a class="b" href="<?= e(url('')) ?>" target="_blank">Ver sitio</a></div>
    </form>
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
<script src="<?= e(url('admin/vendor/quill.js')) ?>"></script>
<script>
document.querySelectorAll('.rich-ed').forEach(function (el) {
  var q = new Quill(el, { theme: 'snow', modules: { toolbar: [[{ header: [2, 3, false] }], ['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], ['link', 'blockquote'], ['clean']] } });
  var hidden = el.closest('.fld').querySelector('input[type=hidden]');
  q.on('text-change', function () { hidden.value = q.root.innerHTML === '<p><br></p>' ? '' : q.root.innerHTML; });
});
</script>
<?php endif; ?>
</body>
</html>
