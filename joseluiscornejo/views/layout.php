<?php
$siteName = s('site_name');
$fullTitle = $pageTitle ? $pageTitle . ' · ' . $siteName : $siteName . ' — ' . ucfirst(mb_strtolower(s('site_tagline')));
$nav = [
    'inicio' => ['', 'Inicio'],
    'libros' => ['libros', 'Libros'],
    'facilitacion' => ['facilitacion', 'Facilitación'],
    'escritos' => ['escritos', 'Escritos y videos'],
    'contacto' => ['contacto', 'Contacto'],
];
$icons = [
    'inicio' => '<path d="M4 11 12 4l8 7v9H4z"/>',
    'libros' => '<path d="M5 4h6v16H5zM13 4h6v16h-6z"/>',
    'facilitacion' => '<circle cx="8" cy="8" r="3"/><circle cx="16" cy="16" r="3"/><path d="M10.5 10.5 13.5 13.5"/>',
    'escritos' => '<rect x="3" y="6" width="18" height="12" rx="3"/><path d="M10 9.5v5l4-2.5z"/>',
    'contacto' => '<path d="M4 20l1.3-4A8 8 0 1 1 8 18.7z"/>',
];
$f = flash();
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($fullTitle) ?></title>
<meta name="description" content="<?= e(s('meta_description')) ?>">
<meta name="theme-color" content="#0E0D0C">
<meta property="og:title" content="<?= e($fullTitle) ?>">
<meta property="og:description" content="<?= e(s('meta_description')) ?>">
<meta property="og:image" content="<?= e(abs_url(s('hero_image', 'assets/img/portada.webp'))) ?>">
<meta property="og:type" content="website">
<link rel="icon" href="<?= e(asset(s('logo') ?: 'assets/img/portada.webp')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;700;800&family=Inter:wght@400;500;600;700&family=Lora:ital@0;1&family=Allura&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=1">
</head>
<body>
<header class="topbar">
  <div class="wrap topbar-in">
    <a class="brand" href="<?= e(url('')) ?>">
      <?php if (s('logo')): ?>
        <img src="<?= e(asset(s('logo'))) ?>" alt="<?= e($siteName) ?>" class="brand-logo">
      <?php else: ?>
        <span class="brand-name"><?= e($siteName) ?></span>
      <?php endif; ?>
      <span class="brand-tag"><?= e(s('site_tagline')) ?></span>
    </a>
    <nav class="mainnav" aria-label="Principal">
      <?php foreach ($nav as $k => [$href, $label]): ?>
        <a href="<?= e(url($href)) ?>" class="<?= $activeNav === $k ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="topbar-r">
    <?= social_icons('socials-top') ?>
    <a class="cartbtn" href="<?= e(url('carrito')) ?>" aria-label="Carrito, <?= cart_count() ?> artículos">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 7h12l-1 13H7z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>
      <span><?= cart_count() ?></span>
    </a>
    </div>
  </div>
</header>

<?php if ($f): ?>
  <div class="wrap"><div class="flash <?= $f['t'] === 'error' ? 'flash-err' : '' ?>" role="status"><?= e($f['m']) ?></div></div>
<?php endif; ?>

<main><?= $content ?></main>

<footer class="footer">
  <div class="wrap footer-soc">
    <span class="script"><?= e($siteName) ?></span>
    <?= social_icons('socials-foot') ?>
  </div>
  <div class="wrap footer-in">
    <span>© <?= date('Y') ?> <?= e($siteName) ?> · <?= e($_SERVER['HTTP_HOST'] ?? '') ?></span>
    <span><?= e(s('footer_note')) ?></span>
  </div>
</footer>

<a class="wa-float" href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener" aria-label="Escribir por WhatsApp Business">
  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l1.3-4A8 8 0 1 1 8 18.7z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8A4 4 0 0 1 10.2 11l.8-1-1-2z"/></svg>
</a>

<nav class="tabbar" aria-label="Navegación móvil">
  <?php foreach ($nav as $k => [$href, $label]): ?>
    <a href="<?= e(url($href)) ?>" class="<?= $activeNav === $k ? 'on' : '' ?>">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icons[$k] ?></svg>
      <?= e($k === 'escritos' ? 'Escritos' : $label) ?>
    </a>
  <?php endforeach; ?>
</nav>
</body>
</html>
