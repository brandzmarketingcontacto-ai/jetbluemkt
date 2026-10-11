<?php
// Landing pública: todo el contenido sale de la base de datos y se edita desde /admin.

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require_installed('install.php');
$csrf = csrf_token(); // Inicia la sesión antes de enviar cualquier HTML.
// La página cambia desde el panel: que ni el navegador ni la caché de Hostinger (LiteSpeed) guarden una copia vieja.
header('Cache-Control: no-cache, must-revalidate');
header('X-LiteSpeed-Cache-Control: no-cache');

$pdo = db();
$sections = $pdo->query('SELECT * FROM sections WHERE visible = 1 ORDER BY position, id')->fetchAll();
$dresses = $pdo->query('SELECT * FROM dresses WHERE visible = 1 ORDER BY position, id')->fetchAll();
$accessories = $pdo->query('SELECT * FROM accessories WHERE visible = 1 ORDER BY position, id')->fetchAll();
$faqs = $pdo->query('SELECT * FROM faqs WHERE visible = 1 ORDER BY position, id')->fetchAll();

$categories = [
    'rosa' => 'Rosa', 'azul' => 'Azul', 'verde' => 'Verde y plata', 'lila' => 'Lila',
    'charro' => 'Charro', 'rojo' => 'Rojo y vino', 'marfil' => 'Marfil y dorado', 'negro' => 'Negro',
];
$usedCats = array_values(array_unique(array_column($dresses, 'category')));

$waCita = wa_link(setting('wa_message', 'Hola, quiero agendar una cita'));
$siteTitle = setting('site_title', 'Vestidos para Quinceañeras Brooklyn');

function section_link(array $s, string $waCita): string
{
    switch ($s['link']) {
        case 'coleccion':
        case 'visitanos':
        case 'salon':
        case 'accesorios':
            return '#' . $s['link'];
        case 'web':
            return safe_url(setting('salon_web', 'https://www.emaeventsvenue.com'));
        default:
            if ($s['kind'] === 'salon') {
                return wa_link('Hola, quiero información del salón EMA Events Venue');
            }
            if ($s['kind'] === 'accesorios') {
                return wa_link('Hola, quiero información de ramos y crinolinas');
            }
            if ($s['kind'] === 'equipo') {
                return wa_link('Hola, me interesa unirme al equipo de ventas');
            }
            return $waCita;
    }
}

/** Título escapado; lo escrito entre *asteriscos* se resalta en cursiva de color. */
function title_html(string $t, bool $autoAccent = false): string
{
    $h = e($t);
    if (str_contains($t, '*')) {
        return preg_replace('/\*(.+?)\*/u', '<em>$1</em>', $h) ?? $h;
    }
    // Portada sin asteriscos: resalta desde la palabra "desde" (ej. "desde $999.").
    if ($autoAccent && preg_match('/^(.*?)(\bdesde\b.*)$/iu', $h, $m)) {
        return $m[1] . '<em>' . $m[2] . '</em>';
    }
    return $h;
}

function img_src(string $url): string
{
    return e(safe_url($url));
}

$navLabels = ['coleccion' => 'Colección', 'accesorios' => 'Accesorios', 'como' => 'Cómo funciona', 'visitanos' => 'Visítanos', 'salon' => 'Salón de eventos'];
$nav = [];
foreach ($sections as $s) {
    if (isset($navLabels[$s['kind']])) {
        $nav[$s['kind']] = $navLabels[$s['kind']];
    }
}
$desc = plain_text($sections[0]['body'] ?? '') ?: setting('tagline');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($siteTitle) ?> — Vestidos de XV desde $999</title>
<meta name="theme-color" content="#2B0B1E">
<meta name="description" content="<?= e(mb_substr($desc, 0, 160)) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="es_US">
<meta property="og:title" content="<?= e($siteTitle) ?>">
<meta property="og:description" content="<?= e(mb_substr($desc, 0, 160)) ?>">
<meta property="og:image" content="<?= e(base_url() . safe_url($sections[0]['image'] ?? setting('logo'))) ?>">
<meta property="og:url" content="<?= e(base_url()) ?>">
<link rel="canonical" href="<?= e(base_url()) ?>">
<link rel="icon" type="image/png" href="<?= e(asset('assets/img/icon.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('assets/img/icon.png')) ?>">
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'ClothingStore',
    'name' => $siteTitle,
    'description' => setting('tagline'),
    'url' => base_url(),
    'image' => base_url() . safe_url(setting('logo', 'assets/img/logo.png')),
    'telephone' => '+1' . digits(setting('whatsapp')),
    'priceRange' => 'Desde ' . setting('hero_badge', '$999'),
    'address' => ['@type' => 'PostalAddress', 'streetAddress' => '1510 Ave H', 'addressLocality' => 'Brooklyn', 'addressRegion' => 'NY', 'postalCode' => '11230', 'addressCountry' => 'US'],
    'sameAs' => array_values(array_filter([safe_url(setting('facebook_url'))])),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500;1,600&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
</head>
<body>

<?php if (setting('topbar') !== ''): ?>
<div class="topbar"><?= e(setting('topbar')) ?> — <a href="<?= e(wa_link()) ?>"><?= e(setting('whatsapp')) ?></a></div>
<?php endif; ?>

<header class="header">
  <nav class="wrap nav">
    <a href="#inicio" class="brand" aria-label="<?= e($siteTitle) ?> — inicio"><img src="<?= img_src(setting('logo', 'assets/img/logo.png')) ?>" alt="<?= e($siteTitle) ?>"></a>
    <details class="menu">
      <summary aria-label="Abrir menú"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>Menú</summary>
      <div class="menu-panel">
        <?php foreach ($nav as $id => $label): ?><a href="#<?= e($id) ?>"><?= e($label) ?></a><?php endforeach; ?>
        <a href="<?= e(wa_link()) ?>">WhatsApp <?= e(setting('whatsapp')) ?></a>
      </div>
    </details>
    <div class="nav-links">
      <?php foreach ($nav as $id => $label): ?><a href="#<?= e($id) ?>"><?= e($label) ?></a><?php endforeach; ?>
      <a class="btn btn-sm" href="<?= e($waCita) ?>">Agendar cita</a>
    </div>
  </nav>
</header>

<main id="inicio">
<?php foreach ($sections as $s):
    $kind = $s['kind'];
    $link = section_link($s, $waCita);
    $bg = 'bg-' . preg_replace('/[^a-z]/', '', $s['bg']);
    $id = e($kind === 'custom' ? 's-' . $s['id'] : $kind);
    switch ($kind):

    case 'hero': ?>
<section id="<?= $id ?>" class="hero <?= $bg ?>">
  <div class="wrap hero-grid">
    <div class="hero-copy">
      <?php if ($s['eyebrow'] !== ''): ?><span class="pill"><?= e($s['eyebrow']) ?></span><?php endif; ?>
      <h1><?= title_html($s['title'], true) ?></h1>
      <div class="rich lead"><?= $s['body'] ?></div>
      <div class="actions">
        <?php if ($s['cta'] !== ''): ?><a class="btn btn-lg" href="<?= e($link) ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.4A8.4 8.4 0 1 1 21 11.5z"/></svg><?= e($s['cta']) ?></a><?php endif; ?>
        <a class="btn btn-lg btn-outline" href="#coleccion">Ver colección</a>
      </div>
      <ul class="checks"><li>Aparta el tuyo hoy</li><li>Atención por cita</li><li><?= e(setting('address')) ?></li></ul>
    </div>
    <div class="hero-media">
      <?php if ($s['image'] !== ''): ?><div class="arch"><img src="<?= img_src($s['image']) ?>" alt="Vestido de quinceañera" fetchpriority="high" width="800" height="1000"></div><?php endif; ?>
      <?php if (setting('hero_inset') !== ''): ?><div class="inset"><img src="<?= img_src(setting('hero_inset')) ?>" alt="Vestido de quinceañera estilo charro"></div><?php endif; ?>
      <div class="badge-price"><small>VESTIDOS DESDE</small><strong><?= e(setting('hero_badge', '$999')) ?></strong></div>
      <div class="badge-mx"><span></span>Hecho en México</div>
    </div>
  </div>
</section>
<?php break;

    case 'valor': ?>
<section id="<?= $id ?>" class="band <?= $bg ?>">
  <div class="wrap rich band-list"><?= $s['body'] ?></div>
</section>
<?php break;

    case 'coleccion': ?>
<section id="<?= $id ?>" class="block <?= $bg ?>">
  <div class="wrap">
    <div class="block-head">
      <div class="head-copy">
        <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif; ?>
        <h2><?= title_html($s['title']) ?></h2>
        <div class="rich muted"><?= $s['body'] ?></div>
      </div>
      <div class="chips" role="group" aria-label="Filtrar por estilo">
        <button type="button" class="chip is-on" data-filter="todos" aria-pressed="true">Todos</button>
        <?php foreach ($categories as $k => $label): if (!in_array($k, $usedCats, true)) continue; ?>
          <button type="button" class="chip" data-filter="<?= e($k) ?>" aria-pressed="false"><?= e($label) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="grid-dresses" id="dresses">
      <?php foreach ($dresses as $d):
        $q = wa_link('Hola, me interesa el vestido "' . $d['name'] . '". ¿Cuándo puedo ir a verlo?'); ?>
      <article class="card dress" data-cat="<?= e($d['category']) ?>">
        <div class="dress-img">
          <?php if ($d['image'] !== ''): ?><img src="<?= img_src($d['image']) ?>" alt="Vestido <?= e($d['name']) ?>" loading="lazy"><?php endif; ?>
          <?php if ($d['tag'] !== ''): ?><span class="tag"><?= e($d['tag']) ?></span><?php endif; ?>
        </div>
        <div class="dress-body">
          <div class="dress-row"><h3><?= e($d['name']) ?></h3><span class="price">desde <?= e($d['price']) ?></span></div>
          <a class="btn-soft" href="<?= e($q) ?>"><?= e($s['cta'] !== '' ? $s['cta'] : 'Preguntar por este vestido') ?></a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <div class="center"><button type="button" class="btn btn-outline" id="more" hidden>Ver más</button></div>
  </div>
</section>
<?php break;

    case 'accesorios': ?>
<section id="<?= $id ?>" class="block <?= $bg ?>">
  <div class="wrap">
    <div class="block-head">
      <div class="head-copy">
        <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif; ?>
        <h2><?= title_html($s['title']) ?></h2>
        <div class="rich muted"><?= $s['body'] ?></div>
      </div>
      <?php if ($s['cta'] !== ''): ?><a class="link-strong" href="<?= e($link) ?>"><?= e($s['cta']) ?> →</a><?php endif; ?>
    </div>
    <div class="grid-acc">
      <?php foreach ($accessories as $a): ?>
      <figure><div class="acc-img"><img src="<?= img_src($a['image']) ?>" alt="<?= e($a['name']) ?>" loading="lazy"></div><figcaption><?= e($a['name']) ?></figcaption></figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php break;

    case 'como': ?>
<section id="<?= $id ?>" class="block <?= $bg ?>">
  <div class="wrap">
    <div class="block-head center-head">
      <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif; ?>
      <h2><?= title_html($s['title']) ?></h2>
    </div>
    <div class="rich steps"><?= $s['body'] ?></div>
  </div>
</section>
<?php break;

    case 'visitanos': ?>
<section id="<?= $id ?>" class="block <?= $bg ?>">
  <div class="wrap">
    <div class="visit">
      <div class="visit-copy">
        <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif; ?>
        <h2><?= title_html($s['title']) ?></h2>
        <div class="rich hours"><?= $s['body'] ?></div>
        <p class="addr"><strong><?= e(setting('address')) ?></strong><br>WhatsApp <a href="<?= e(wa_link()) ?>"><?= e(setting('whatsapp')) ?></a><?php if (setting('phone2') !== ''): ?> · Tel. <a href="tel:+1<?= e(digits(setting('phone2'))) ?>"><?= e(setting('phone2')) ?></a><?php endif; ?></p>
        <div class="actions">
          <?php if ($s['cta'] !== ''): ?><a class="btn" href="<?= e($link) ?>"><?= e($s['cta']) ?></a><?php endif; ?>
          <a class="btn btn-outline" href="<?= e(safe_url(setting('maps_url'))) ?>" target="_blank" rel="noopener">Cómo llegar</a>
        </div>
      </div>
      <?php if ($s['image'] !== ''): ?>
      <div class="visit-img"><img src="<?= img_src($s['image']) ?>" alt="Sala de exhibición de vestidos" loading="lazy"><span>Nuestra sala de exhibición · <?= e(setting('address')) ?></span></div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php break;

    case 'salon': ?>
<section id="<?= $id ?>" class="salon <?= $bg ?>">
  <div class="wrap salon-grid">
    <?php if ($s['image'] !== ''): ?>
    <div class="salon-img"><img src="<?= img_src($s['image']) ?>" alt="Salón EMA Events Venue decorado" loading="lazy"><span class="ema">EMA</span><span class="cap">Nuestro salón · <?= e(setting('address')) ?></span></div>
    <?php endif; ?>
    <div class="salon-copy">
      <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow gold"><?= e($s['eyebrow']) ?></span><?php endif; ?>
      <h2><?= title_html($s['title']) ?></h2>
      <div class="rich"><?= $s['body'] ?></div>
      <div class="tags">
        <span>XV años</span><span>Bodas</span><span>Bautizos</span><span>Cumpleaños</span>
        <?php if (setting('salon_capacity') !== ''): ?><span>Capacidad: <?= e(setting('salon_capacity')) ?></span><?php endif; ?>
      </div>
      <div class="actions">
        <?php if ($s['cta'] !== ''): ?><a class="btn btn-gold" href="<?= e($link) ?>"><?= e($s['cta']) ?></a><?php endif; ?>
        <?php if (setting('salon_web') !== ''): ?><a class="btn btn-ghost-light" href="<?= e(safe_url(setting('salon_web'))) ?>" target="_blank" rel="noopener"><?= e(preg_replace('~^https?://(www\.)?~', '', setting('salon_web'))) ?></a><?php endif; ?>
      </div>
      <small class="hashtag">#BrooklynPartyHall · Salón para eventos · Event Space</small>
    </div>
  </div>
</section>
<?php break;

    case 'faq': ?>
<section id="<?= $id ?>" class="block <?= $bg ?>">
  <div class="wrap narrow">
    <h2 class="center-text"><?= title_html($s['title']) ?></h2>
    <?php if (plain_text($s['body']) !== ''): ?><div class="rich muted center-text"><?= $s['body'] ?></div><?php endif; ?>
    <div class="faqs">
      <?php foreach ($faqs as $i => $f): ?>
      <details<?= $i === 0 ? ' open' : '' ?>><summary><?= e($f['question']) ?></summary><div class="rich"><?= $f['answer'] ?></div></details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php break;

    case 'cta': ?>
<section id="<?= $id ?>" class="block">
  <div class="wrap">
    <div class="cta-box <?= $bg ?>">
      <h2><?= title_html($s['title']) ?></h2>
      <div class="rich"><?= $s['body'] ?></div>
      <?php if ($s['cta'] !== ''): ?><a class="btn btn-lg btn-invert" href="<?= e($link) ?>"><?= e($s['cta']) ?></a><?php endif; ?>
    </div>
  </div>
</section>
<?php break;

    case 'equipo': ?>
<section id="<?= $id ?>" class="block tight <?= $bg ?>">
  <div class="wrap">
    <div class="team"><div><strong><?= title_html($s['title']) ?></strong> <span class="rich inline"><?= $s['body'] ?></span></div><?php if ($s['cta'] !== ''): ?><a class="link-strong" href="<?= e($link) ?>"><?= e($s['cta']) ?> →</a><?php endif; ?></div>
  </div>
</section>
<?php break;

    default: ?>
<section id="<?= $id ?>" class="block <?= $bg ?>">
  <div class="wrap custom<?= $s['image'] !== '' ? ' has-img' : '' ?>">
    <?php if ($s['image'] !== ''): ?><div class="custom-img"><img src="<?= img_src($s['image']) ?>" alt="" loading="lazy"></div><?php endif; ?>
    <div class="custom-copy">
      <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif; ?>
      <h2><?= title_html($s['title']) ?></h2>
      <div class="rich"><?= $s['body'] ?></div>
      <?php if ($s['cta'] !== ''): ?><div class="actions"><a class="btn" href="<?= e($link) ?>"><?= e($s['cta']) ?></a></div><?php endif; ?>
    </div>
  </div>
</section>
<?php
    endswitch;
endforeach; ?>
</main>

<footer class="footer">
  <div class="wrap footer-grid">
    <div class="footer-brand">
      <img src="<?= img_src(setting('logo_white', 'assets/img/logo-white.png')) ?>" alt="<?= e($siteTitle) ?>">
      <span><?= e(setting('tagline')) ?></span>
      <button type="button" class="admin-btn" id="admin-open"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>Admin</button>
    </div>
    <div><strong>Dirección</strong><span><?= e(setting('address')) ?></span><span>Solo por cita · By appointment only</span></div>
    <div><strong>Contacto</strong><a href="<?= e(wa_link()) ?>">WhatsApp <?= e(setting('whatsapp')) ?></a><?php if (setting('phone2') !== ''): ?><a href="tel:+1<?= e(digits(setting('phone2'))) ?>"><?= e(setting('phone2')) ?></a><?php endif; ?><?php if (setting('facebook_url') !== ''): ?><a href="<?= e(safe_url(setting('facebook_url'))) ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?></div>
  </div>
</footer>

<?php if (setting('float_button', '1') === '1'): ?>
<a class="wa-float" href="<?= e($waCita) ?>" aria-label="Escríbenos por WhatsApp"><span class="wa-ring" aria-hidden="true"></span>
  <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.4A8.4 8.4 0 1 1 21 11.5z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8a3.6 3.6 0 0 1-1.8-1.8l.8-1-1-2L9 9.5z"/></svg>
  <span class="wa-label"><?= e(setting('float_label', '¿Agendamos tu cita?')) ?></span>
</a>
<?php endif; ?>

<dialog class="login" id="admin-dialog" aria-labelledby="login-title">
  <form method="post" action="admin/login.php" class="login-form">
    <div class="login-head">
      <div><h2 id="login-title">Panel de administración</h2><p>Ingresa la contraseña para continuar</p></div>
      <button type="button" class="x" id="admin-close" aria-label="Cerrar">×</button>
    </div>
    <?php if (isset($_GET['login']) && $_GET['login'] === 'error'): ?><p class="login-err" role="alert">Contraseña incorrecta. Inténtalo de nuevo.</p><?php endif; ?>
    <label>Contraseña<input type="password" name="password" autocomplete="current-password" required autofocus></label>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <button class="btn btn-lg" type="submit">Ingresar</button>
  </form>
</dialog>

<script src="<?= e(asset('assets/js/site.js')) ?>"></script>
<?php if (isset($_GET['login'])): ?><script>document.getElementById('admin-dialog').showModal();</script><?php endif; ?>
</body>
</html>
