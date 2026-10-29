<?php
/** @var array $meta @var string $content @var string $active */
$l = lang();
$store = (string)setting('store_name', 'Sofi Love & Faith');
$logo = brand_logo();
$shape = setting('logo_shape', 'circle');
$logoSize = max(32, min(96, (int)setting('logo_size', '56')));
$offers = lines(st('offer_bar'));
$flash = flash();
$soc = socials();
$cartN = cart_count();
$nav = [['/', 'home', 'home'], ['/shop', 'shop', 'shop'], ['/shop?sale=1', 'sale', 'sale'], ['/contact', 'contact', 'contact']];
?><!doctype html>
<html lang="<?= e($l) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($meta['title']) ?></title>
<meta name="description" content="<?= e($meta['desc']) ?>">
<?php if ($meta['keywords']): ?><meta name="keywords" content="<?= e($meta['keywords']) ?>"><?php endif; ?>
<?php if ($meta['noindex'] || !empty($preview)): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<link rel="canonical" href="<?= e($meta['canonical']) ?>">
<?php if (setting_on('seo_hreflang', true)): foreach (LANGS as $hl): ?>
<link rel="alternate" hreflang="<?= $hl ?>" href="<?= e($meta['canonical'] . '?lang=' . $hl) ?>">
<?php endforeach; endif; ?>
<meta property="og:site_name" content="<?= e($store) ?>">
<meta property="og:type" content="<?= e($meta['og_type']) ?>">
<meta property="og:title" content="<?= e($meta['og_title']) ?>">
<meta property="og:description" content="<?= e($meta['og_desc']) ?>">
<meta property="og:url" content="<?= e($meta['canonical']) ?>">
<meta property="og:image" content="<?= e($meta['og_image']) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="<?= e($meta['locale']) ?>">
<meta property="og:locale:alternate" content="<?= e($meta['alt_locale']) ?>">
<meta name="twitter:card" content="<?= e(setting('twitter_card', 'summary_large_image')) ?>">
<meta name="twitter:title" content="<?= e($meta['og_title']) ?>">
<meta name="twitter:description" content="<?= e($meta['og_desc']) ?>">
<meta name="twitter:image" content="<?= e($meta['og_image']) ?>">
<?php if ($v = setting('gsc_verify', '')): ?><meta name="google-site-verification" content="<?= e(preg_replace('/^google-site-verification=/', '', $v)) ?>"><?php endif; ?>
<link rel="icon" href="<?= e(media_url(brand_favicon())) ?>">
<link rel="apple-touch-icon" href="<?= e(media_url(brand_favicon())) ?>">
<meta name="theme-color" content="#B83A6B">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&family=Great+Vibes&family=Jost:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('/assets/css/site.css')) ?>?v=<?= APP_VERSION ?>">
<?php foreach ($meta['jsonld'] as $ld): ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
<?php if ($ga = trim((string)setting('ga_id', ''))): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($ga) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',<?= json_encode($ga) ?>);</script>
<?php endif; ?>
<?php if ($px = trim((string)setting('pixel_id', ''))): ?>
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',<?= json_encode($px) ?>);fbq('track','PageView');</script>
<?php endif; ?>
</head>
<body>
<a class="skip" href="#main"><?= $l === 'es' ? 'Saltar al contenido' : 'Skip to content' ?></a>
<?php if (!empty($preview)): ?><div class="preview-bar">Vista previa del borrador — los clientes aún no ven estos cambios</div><?php endif; ?>

<?php if (setting_on('offer_bar_on', true) && $offers): ?>
<div class="mq-wrap" style="--mq-speed: <?= max(10, (int)setting('offer_bar_speed', '32')) ?>s">
  <?php $link = (string)setting('offer_bar_link', ''); ?>
  <<?= $link ? 'a href="' . e(url($link)) . '"' : 'div' ?> class="mq-track" aria-label="<?= e(implode(' · ', $offers)) ?>">
    <?php for ($i = 0; $i < 2; $i++): foreach ($offers as $o): ?>
      <span <?= $i ? 'aria-hidden="true"' : '' ?>><?= icon('heart', 14, 'fill') ?><?= e($o) ?></span>
    <?php endforeach; endfor; ?>
  </<?= $link ? 'a' : 'div' ?>>
</div>
<?php endif; ?>

<header class="site-head">
  <div class="wrap head-row">
    <a class="brand" href="<?= e(url('/')) ?>">
      <img src="<?= e(media_url($logo)) ?>" alt="<?= e($store) ?>" class="logo shape-<?= e($shape) ?>" style="width:<?= $logoSize ?>px;height:<?= $logoSize ?>px">
      <?php if (setting_on('logo_show_name', true)): ?>
      <span class="brand-text"><span class="script"><?= e(setting('brand_script', 'Sofi')) ?></span><span class="brand-sub"><?= e(setting('brand_sub', 'Love & Faith')) ?></span></span>
      <?php endif; ?>
    </a>
    <nav class="main-nav" aria-label="Main" id="main-nav">
      <?php foreach ($nav as [$href, $key, $slug]): ?>
        <a href="<?= e(url($href)) ?>" class="<?= $active === $slug ? 'on' : '' ?>"><?= e(t($key)) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="head-tools">
      <a class="icon-btn" href="<?= e(url('/shop')) ?>#search" aria-label="<?= e(t('search')) ?>"><?= icon('search') ?></a>
      <?php if (setting_on('lang_toggle', true)): ?>
      <div class="lang-toggle" role="group" aria-label="<?= e(t('language')) ?>">
        <a href="<?= e(lang_url('en')) ?>" class="<?= $l === 'en' ? 'on' : '' ?>" hreflang="en" <?= $l === 'en' ? 'aria-current="true"' : '' ?>>EN</a>
        <a href="<?= e(lang_url('es')) ?>" class="<?= $l === 'es' ? 'on' : '' ?>" hreflang="es" <?= $l === 'es' ? 'aria-current="true"' : '' ?>>ES</a>
      </div>
      <?php endif; ?>
      <a class="icon-btn cart-btn" href="<?= e(url('/cart')) ?>" aria-label="<?= e(t('cart')) ?> (<?= $cartN ?>)"><?= icon('bag') ?><span class="cart-n" data-cart-count <?= $cartN ? '' : 'hidden' ?>><?= $cartN ?></span></a>
      <button class="icon-btn menu-btn" type="button" aria-label="Menu" aria-controls="main-nav" aria-expanded="false" data-menu><?= icon('menu') ?></button>
    </div>
  </div>
</header>

<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>" role="status"><div class="wrap"><?= e($flash['msg']) ?></div></div>
<?php endif; ?>

<main id="main"><?= $content ?></main>

<footer class="site-foot">
  <div class="wrap foot-grid">
    <div class="foot-brand">
      <?php if ($ll = setting('logo_light', '')): ?>
        <img src="<?= e(media_url($ll)) ?>" alt="<?= e($store) ?>" class="foot-logo">
      <?php else: ?>
        <span class="script xl"><?= e(setting('brand_script', 'Sofi')) ?></span>
        <span class="brand-sub light"><?= e(setting('brand_sub', 'Love & Faith')) ?></span>
      <?php endif; ?>
      <p><?= e(st('footer_text', 'Jewelry, perfumes and little details made with love and faith.')) ?></p>
      <div class="soc">
        <?php foreach ($soc as $k => $u): ?>
          <a href="<?= e($u) ?>" target="_blank" rel="noopener" aria-label="<?= e(ucfirst($k)) ?>"><?= icon($k, 18) ?></a>
        <?php endforeach; ?>
        <a href="<?= e(wa_link()) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= icon('whatsapp', 18) ?></a>
      </div>
    </div>
    <div class="foot-col">
      <strong><?= e(t('shop')) ?></strong>
      <?php foreach (rows('SELECT * FROM categories ORDER BY sort, id LIMIT 6') as $c): ?>
        <a href="<?= e(url('/shop?cat=' . $c['slug'])) ?>"><?= e(tr($c, 'name')) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="foot-col">
      <strong><?= e(t('help')) ?></strong>
      <a href="<?= e(url('/contact')) ?>"><?= e(t('contact')) ?></a>
      <a href="<?= e(url('/contact')) ?>#faq"><?= e(t('shipping_returns')) ?></a>
      <a href="<?= e(url('/cart')) ?>"><?= e(t('cart')) ?></a>
    </div>
    <div class="foot-col">
      <strong><?= e(t('talk_to_us')) ?></strong>
      <a class="foot-wa" href="<?= e(wa_link()) ?>" target="_blank" rel="noopener">WhatsApp · <?= e(wa_display()) ?></a>
      <span><?= e(st('footer_help', 'We answer orders, sizes and gift questions personally.')) ?></span>
      <span class="secure"><?= icon('lock', 16) ?><?= e(t('secure_stripe')) ?></span>
    </div>
  </div>
  <div class="wrap foot-bottom">
    <span class="rights">© <?= date('Y') ?> <?= e($store) ?>. <?= e(t('rights')) ?>
      <a class="admin-link" href="<?= e(url('/admin')) ?>"><?= icon('lock', 13) ?>Admin</a>
    </span>
    <span>Visa · Mastercard · Amex · Apple Pay · Google Pay</span>
  </div>
</footer>

<?php if (setting_on('whatsapp_float', true)): ?>
<a class="wa-float" href="<?= e(wa_link()) ?>" target="_blank" rel="noopener" aria-label="<?= e(t('chat_wa')) ?>"><?= icon('whatsapp', 28) ?></a>
<?php endif; ?>

<?php if (setting_on('ask_lang', true) && !lang_chosen() && empty($preview)): ?>
<div class="lang-modal" role="dialog" aria-modal="true" aria-labelledby="lang-title" data-lang-modal>
  <div class="lang-card">
    <img src="<?= e(media_url($logo)) ?>" alt="<?= e($store) ?>" class="logo shape-<?= e($shape) ?>">
    <p class="script lg">Welcome · Bienvenida</p>
    <h2 id="lang-title">Which language do you prefer?<br><span>¿En qué idioma prefieres ver la tienda?</span></h2>
    <div class="lang-choices">
      <a href="<?= e(lang_url('en')) ?>" class="<?= $l === 'en' ? 'on' : '' ?>"><small>EN</small>English</a>
      <a href="<?= e(lang_url('es')) ?>" class="<?= $l === 'es' ? 'on' : '' ?>"><small>ES</small>Español</a>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="<?= e(url('/assets/js/site.js')) ?>?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>
