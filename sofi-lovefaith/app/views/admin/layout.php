<?php
/** @var string $title @var string $active @var string $content */
$counts = admin_nav_counts();
$nav = [
    ['dashboard', '/admin', 'Resumen', 'dashboard', 0],
    ['pages', '/admin/pages', 'Editor de páginas', 'pages', 0],
    ['products', '/admin/products', 'Productos', 'bag', 0],
    ['orders', '/admin/orders', 'Pedidos', 'orders', $counts['orders']],
    ['forms', '/admin/forms', 'Formularios', 'mail', $counts['forms']],
    ['offers', '/admin/offers', 'Ofertas y cupones', 'tag', 0],
    ['media', '/admin/media', 'Medios', 'image', 0],
    ['seo', '/admin/seo', 'SEO y Open Graph', 'seo', 0],
    ['settings', '/admin/settings', 'Ajustes', 'settings', 0],
];
$flash = flash();
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<meta name="base" content="<?= e(base_path()) ?>">
<title><?= e($title) ?> — Panel Sofi</title>
<link rel="icon" href="<?= e(media_url(brand_favicon())) ?>">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Great+Vibes&family=Jost:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('/assets/css/admin.css')) ?>?v=<?= APP_VERSION ?>">
</head>
<body class="<?= !empty($wide) ? 'wide' : '' ?>">
<div class="shell">
  <aside class="side" id="side">
    <a class="side-brand" href="<?= e(url('/admin')) ?>">
      <img src="<?= e(media_url(brand_logo())) ?>" alt="">
      <span><span class="script"><?= e(setting('brand_script', 'Sofi')) ?></span><small>SUPER ADMIN</small></span>
    </a>
    <nav aria-label="Panel">
      <?php foreach ($nav as [$key, $href, $label, $ic, $badge]): ?>
        <a href="<?= e(url($href)) ?>" class="<?= $active === $key ? 'on' : '' ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>><?= icon($ic, 18) ?><span><?= e($label) ?></span><?php if ($badge): ?><em><?= (int)$badge ?></em><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= icon('external', 18) ?><span>Ver tienda</span></a>
      <a href="<?= e(url('/admin/logout')) ?>"><?= icon('logout', 18) ?><span>Cerrar sesión</span></a>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu" type="button" data-side-toggle aria-label="Menú" aria-controls="side"><?= icon('menu') ?></button>
      <span class="top-title"><?= e($title) ?></span>
      <?php if (setting('admin_password_default', '0') === '1'): ?>
        <a class="warn-chip" href="<?= e(url('/admin/settings#seguridad')) ?>">Cambia la contraseña inicial (admin123)</a>
      <?php endif; ?>
    </header>
    <?php if ($flash): ?><div class="flash <?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
    <div class="content"><?= $content ?></div>
  </div>
</div>

<div class="modal" id="media-modal" hidden role="dialog" aria-modal="true" aria-labelledby="mm-title">
  <div class="modal-card">
    <div class="modal-head">
      <h2 id="mm-title">Elegir archivo</h2>
      <label class="btn btn-primary sm"><?= icon('upload', 16) ?>Subir archivos<input type="file" multiple hidden data-mm-upload accept="image/*,video/mp4,video/webm,video/quicktime"></label>
      <button class="icon-btn" type="button" data-mm-close aria-label="Cerrar"><?= icon('close') ?></button>
    </div>
    <div class="mm-grid" data-mm-grid><p class="muted">Cargando…</p></div>
  </div>
</div>
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script src="<?= e(url('/assets/js/admin.js')) ?>?v=<?= APP_VERSION ?>"></script>
<?php foreach ($scripts ?? [] as $s): ?><script src="<?= e(url($s)) ?>?v=<?= APP_VERSION ?>"></script><?php endforeach; ?>
</body>
</html>
