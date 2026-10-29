<?php
declare(strict_types=1);

/*
 * Page blocks. A page is a JSON list of blocks:
 *   {id, type, visible, data:{key:value}, i18n:{en:{key:value}, es:{key:value}}, style:{bg, pad, hide_mobile}}
 * Fields flagged "tr" live in i18n (one value per language); the rest live in data.
 * The same schema drives the admin block editor (sent to the browser as JSON).
 */

function block_schemas(): array
{
    $srcOpts = ['featured' => 'Destacados', 'sale' => 'En oferta', 'new' => 'Más nuevos', 'category' => 'Una categoría'];
    return [
        'hero' => ['label' => 'Hero principal', 'icon' => 'hero', 'fields' => [
            ['script', 'Texto script (cursiva)', 'text', true],
            ['badge', 'Etiqueta de oferta', 'text', true],
            ['title', 'Título', 'textarea', true],
            ['text', 'Descripción', 'textarea', true],
            ['cta_text', 'Botón — texto', 'text', true],
            ['cta_link', 'Botón — enlace', 'url', false],
            ['cta2', 'Mostrar botón de WhatsApp', 'toggle', false],
            ['cta2_text', 'WhatsApp — texto', 'text', true],
            ['perks', 'Ventajas (una por línea)', 'lines', true],
            ['image_main', 'Imagen grande', 'image', false],
            ['image_2', 'Imagen 2', 'image', false],
            ['image_2_label', 'Etiqueta imagen 2', 'text', true],
            ['image_3', 'Imagen 3', 'image', false],
            ['image_3_label', 'Etiqueta imagen 3', 'text', true],
        ]],
        'perks' => ['label' => 'Beneficios', 'icon' => 'shield', 'fields' => [
            ['items', 'Beneficios: Título | texto (uno por línea)', 'lines', true],
        ]],
        'categories' => ['label' => 'Categorías', 'icon' => 'cats', 'fields' => [
            ['script', 'Texto script', 'text', true],
            ['title', 'Título', 'text', true],
            ['link_text', 'Texto del enlace', 'text', true],
        ]],
        'offer_banner' => ['label' => 'Banner de oferta', 'icon' => 'tag', 'fields' => [
            ['badge', 'Etiqueta', 'text', true],
            ['title', 'Título', 'text', true],
            ['text', 'Texto', 'textarea', true],
            ['code', 'Código de cupón', 'text', false],
            ['ends_at', 'Termina el (cuenta regresiva)', 'datetime', false],
            ['cta_text', 'Botón — texto', 'text', true],
            ['cta_link', 'Botón — enlace', 'url', false],
            ['image', 'Imagen', 'image', false],
        ]],
        'products' => ['label' => 'Productos', 'icon' => 'bag', 'fields' => [
            ['script', 'Texto script', 'text', true],
            ['title', 'Título', 'text', true],
            ['source', 'Mostrar', 'select', false, $srcOpts],
            ['category', 'Categoría (si eliges "Una categoría")', 'category', false],
            ['count', 'Cantidad', 'number', false],
            ['link_text', 'Texto del enlace', 'text', true],
        ]],
        'story' => ['label' => 'Nuestra historia', 'icon' => 'text', 'fields' => [
            ['script', 'Texto script', 'text', true],
            ['title', 'Título', 'text', true],
            ['text', 'Texto (deja una línea vacía entre párrafos)', 'textarea', true],
            ['highlights', 'Destacados: Título | subtítulo', 'lines', true],
            ['image', 'Imagen', 'image', false],
        ]],
        'instagram' => ['label' => 'Feed de Instagram', 'icon' => 'instagram', 'fields' => [
            ['script', 'Texto script', 'text', true],
            ['title', 'Título', 'text', true],
            ['link_text', 'Texto del enlace', 'text', true],
            ['images', 'Fotos', 'images', false],
        ]],
        'image_text' => ['label' => 'Imagen + texto', 'icon' => 'image', 'fields' => [
            ['script', 'Texto script', 'text', true],
            ['title', 'Título', 'text', true],
            ['text', 'Texto', 'textarea', true],
            ['cta_text', 'Botón — texto', 'text', true],
            ['cta_link', 'Botón — enlace', 'url', false],
            ['image', 'Imagen', 'image', false],
            ['reverse', 'Imagen a la derecha', 'toggle', false],
        ]],
        'text' => ['label' => 'Texto', 'icon' => 'text', 'fields' => [
            ['title', 'Título', 'text', true],
            ['text', 'Texto', 'textarea', true],
            ['align', 'Alineación', 'select', false, ['center' => 'Centrado', 'left' => 'Izquierda']],
        ]],
        'video' => ['label' => 'Video', 'icon' => 'video', 'fields' => [
            ['title', 'Título', 'text', true],
            ['url', 'Video (YouTube, Vimeo o archivo MP4)', 'video', false],
        ]],
        'testimonials' => ['label' => 'Testimonios', 'icon' => 'quote', 'fields' => [
            ['title', 'Título', 'text', true],
            ['items', 'Testimonio | Nombre (uno por línea)', 'lines', true],
        ]],
        'faq' => ['label' => 'Preguntas frecuentes', 'icon' => 'help', 'fields' => [
            ['title', 'Título', 'text', true],
            ['items', 'Pregunta | Respuesta (una por línea)', 'lines', true],
        ]],
        'newsletter' => ['label' => 'Suscripción', 'icon' => 'mail', 'fields' => [
            ['title', 'Título', 'text', true],
            ['text', 'Texto', 'textarea', true],
            ['button', 'Botón', 'text', true],
        ]],
        'page_header' => ['label' => 'Encabezado de página', 'icon' => 'text', 'fields' => [
            ['script', 'Texto script', 'text', true],
            ['title', 'Título', 'text', true],
            ['text', 'Texto', 'textarea', true],
        ]],
        'shop_grid' => ['label' => 'Catálogo de productos', 'icon' => 'cats', 'fields' => [
            ['title', 'Título', 'text', true],
            ['text', 'Texto', 'textarea', true],
            ['promo_big', 'Promo — texto grande', 'text', true],
            ['promo_text', 'Promo — texto', 'textarea', true],
        ]],
        'contact' => ['label' => 'Contacto (WhatsApp + formulario)', 'icon' => 'form', 'fields' => [
            ['wa_title', 'Tarjeta WhatsApp — título', 'text', true],
            ['wa_text', 'Tarjeta WhatsApp — texto', 'textarea', true],
            ['form_title', 'Formulario — título', 'text', true],
            ['form_text', 'Formulario — texto', 'textarea', true],
        ]],
        'spacer' => ['label' => 'Espaciador', 'icon' => 'spacer', 'fields' => [
            ['height', 'Altura (px)', 'number', false],
        ]],
    ];
}

function bv(array $b, string $key, $default = '')
{
    $l = lang();
    $v = $b['i18n'][$l][$key] ?? null;
    if ($v === null || $v === '') $v = $b['i18n']['en'][$key] ?? null;
    if ($v === null || $v === '') $v = $b['data'][$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function bd(array $b, string $key, $default = '')
{
    $v = $b['data'][$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function page_blocks(string $slug, bool $draft = false): array
{
    $p = row('SELECT * FROM pages WHERE slug = ?', [$slug]);
    if (!$p) return [];
    $json = ($draft && $p['blocks_draft']) ? $p['blocks_draft'] : $p['blocks'];
    return jdec($json, []);
}

function render_blocks(array $blocks): string
{
    $out = '';
    foreach ($blocks as $b) {
        if (empty($b['type']) || (isset($b['visible']) && !$b['visible'])) continue;
        $fn = 'block_' . $b['type'];
        if (!function_exists($fn)) continue;
        $style = $b['style'] ?? [];
        $cls = 'blk blk-' . $b['type'] . ' bg-' . ($style['bg'] ?? 'default') . ' pad-' . ($style['pad'] ?? 'm');
        if (!empty($style['hide_mobile'])) $cls .= ' hide-mobile';
        $out .= '<section class="' . e($cls) . '" data-block="' . e($b['id'] ?? '') . '">' . $fn($b) . '</section>';
    }
    return $out;
}

/* ---------- Block renderers ---------- */

function block_head(array $b, string $link = '', string $linkText = ''): string
{
    $s = bv($b, 'script');
    $t = bv($b, 'title');
    if ($s === '' && $t === '') return '';
    $h = '<div class="sec-head"><div>';
    if ($s !== '') $h .= '<p class="script">' . e($s) . '</p>';
    if ($t !== '') $h .= '<h2>' . e($t) . '</h2>';
    $h .= '</div>';
    if ($link && $linkText) $h .= '<a class="sec-link" href="' . e(url($link)) . '">' . e($linkText) . '</a>';
    return $h . '</div>';
}

function block_hero(array $b): string
{
    ob_start(); ?>
    <div class="wrap hero">
      <div class="hero-copy">
        <?php if ($v = bv($b, 'badge')): ?><span class="pill"><?= e($v) ?></span><?php endif; ?>
        <?php if ($v = bv($b, 'script')): ?><p class="script lg"><?= e($v) ?></p><?php endif; ?>
        <h1><?= nl2br(e(bv($b, 'title'))) ?></h1>
        <?php if ($v = bv($b, 'text')): ?><p class="lead"><?= nl2br(e($v)) ?></p><?php endif; ?>
        <div class="btn-row">
          <?php if ($v = bv($b, 'cta_text')): ?><a class="btn btn-primary" href="<?= e(url(bd($b, 'cta_link', '/shop'))) ?>"><?= e($v) ?> <?= icon('arrow', 18) ?></a><?php endif; ?>
          <?php if (bd($b, 'cta2')): ?><a class="btn btn-ghost" href="<?= e(wa_link()) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 18, 'wa') ?><?= e(bv($b, 'cta2_text', 'WhatsApp')) ?></a><?php endif; ?>
        </div>
        <?php $perks = lines(bv($b, 'perks')); if ($perks): ?>
        <ul class="ticks"><?php foreach ($perks as $p): ?><li><?= icon('check', 18) ?><?= e($p) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
      <div class="hero-media">
        <?php if ($v = bd($b, 'image_main')): ?><div class="hm-main"><img src="<?= e(media_url($v)) ?>" alt="" fetchpriority="high"></div><?php endif; ?>
        <?php foreach ([2, 3] as $n): if ($v = bd($b, 'image_' . $n)): ?>
          <div class="hm-small"><img src="<?= e(media_url($v)) ?>" alt="" loading="lazy"><?php if ($l = bv($b, 'image_' . $n . '_label')): ?><span class="<?= $n === 3 ? 'tag-rose' : 'tag-white' ?>"><?= e($l) ?></span><?php endif; ?></div>
        <?php endif; endforeach; ?>
      </div>
    </div>
    <?php return (string)ob_get_clean();
}

function block_perks(array $b): string
{
    $icons = ['shield', 'gift', 'lock', 'whatsapp', 'truck', 'return'];
    $h = '<div class="wrap perks">';
    foreach (lines(bv($b, 'items')) as $i => $line) {
        [$title, $text] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        $h .= '<div class="perk"><span class="perk-ic">' . icon($icons[$i % count($icons)], 22) . '</span><span><strong>' . e($title) . '</strong><span>' . e($text) . '</span></span></div>';
    }
    return $h . '</div>';
}

function block_categories(array $b): string
{
    $cats = rows('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.status = "published" AND FIND_IN_SET(c.slug, p.categories)) AS n FROM categories c ORDER BY c.sort, c.id');
    $h = '<div class="wrap">' . block_head($b, '/shop', bv($b, 'link_text')) . '<div class="cat-grid">';
    foreach ($cats as $c) {
        $img = $c['image'] ? '<img src="' . e(media_url($c['image'])) . '" alt="' . e(tr($c, 'name')) . '" loading="lazy">' : '<span class="cat-ph">' . icon('gift', 40) . '</span>';
        $h .= '<a class="cat-card zoom" href="' . e(url('/shop?cat=' . $c['slug'])) . '"><div class="cat-img">' . $img . '</div><span class="cat-meta"><strong>' . e(tr($c, 'name')) . '</strong><span>' . (int)$c['n'] . '</span></span></a>';
    }
    return $h . '</div></div>';
}

function countdown_html(string $endsAt, string $cls = 'countdown'): string
{
    $ts = strtotime($endsAt);
    if (!$ts || $ts < time()) return '';
    $h = '<div class="' . e($cls) . '" data-ends="' . e(date('c', $ts)) . '" aria-label="' . e(t('sale_ends')) . '">';
    foreach (['d' => t('days'), 'h' => t('hours'), 'm' => t('min'), 's' => t('sec')] as $k => $label) {
        $h .= '<span><strong data-u="' . $k . '">00</strong><small>' . e($label) . '</small></span>';
    }
    return $h . '</div>';
}

function block_offer_banner(array $b): string
{
    ob_start(); ?>
    <div class="wrap">
      <div class="offer">
        <div class="offer-copy">
          <?php if ($v = bv($b, 'badge')): ?><span class="pill">&nbsp;<?= e($v) ?>&nbsp;</span><?php endif; ?>
          <h2><?= e(bv($b, 'title')) ?></h2>
          <?php if ($v = bv($b, 'text')): ?><p><?= nl2br(e($v)) ?></p><?php endif; ?>
          <?php if ($c = bd($b, 'code')): ?><p class="code">Code / Código: <strong><?= e($c) ?></strong></p><?php endif; ?>
          <?= countdown_html((string)bd($b, 'ends_at')) ?>
          <?php if ($v = bv($b, 'cta_text')): ?><a class="btn btn-light" href="<?= e(url(bd($b, 'cta_link', '/shop'))) ?>"><?= e($v) ?></a><?php endif; ?>
        </div>
        <?php if ($v = bd($b, 'image')): ?><div class="offer-img"><img src="<?= e(media_url($v)) ?>" alt="" loading="lazy"></div><?php endif; ?>
      </div>
    </div>
    <?php return (string)ob_get_clean();
}

function block_products(array $b): string
{
    $src = bd($b, 'source', 'featured');
    $n = max(1, min(24, (int)bd($b, 'count', 8)));
    $opts = ['limit' => $n];
    if ($src === 'sale') $opts['sale'] = true;
    if ($src === 'new') $opts['sort'] = 'new';
    if ($src === 'featured') $opts['sort'] = 'featured';
    if ($src === 'category') $opts['cat'] = bd($b, 'category');
    $list = products_list($opts);
    $h = '<div class="wrap">' . block_head($b, $src === 'sale' ? '/shop?sale=1' : '/shop', bv($b, 'link_text')) . '<div class="p-grid">';
    foreach ($list as $p) $h .= product_card($p);
    return $h . '</div></div>';
}

function block_story(array $b): string
{
    ob_start(); ?>
    <div class="wrap story">
      <?php if ($v = bd($b, 'image')): ?><div class="story-img"><img src="<?= e(media_url($v)) ?>" alt="" loading="lazy"></div><?php endif; ?>
      <div class="story-copy">
        <?php if ($v = bv($b, 'script')): ?><p class="script lg"><?= e($v) ?></p><?php endif; ?>
        <h2><?= e(bv($b, 'title')) ?></h2>
        <?php foreach (preg_split('/\n\s*\n/', trim((string)bv($b, 'text'))) as $para): if (trim($para) === '') continue; ?>
          <p><?= nl2br(e(trim($para))) ?></p>
        <?php endforeach; ?>
        <?php $hl = lines(bv($b, 'highlights')); if ($hl): ?>
        <div class="highlights"><?php foreach ($hl as $line): [$a, $c] = array_pad(array_map('trim', explode('|', $line, 2)), 2, ''); ?>
          <span><strong><?= e($a) ?></strong><small><?= e($c) ?></small></span>
        <?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php return (string)ob_get_clean();
}

function block_instagram(array $b): string
{
    $link = social_url('instagram');
    $imgs = bd($b, 'images', []);
    if (!is_array($imgs)) $imgs = [];
    $h = '<div class="wrap"><div class="sec-head center"><div>';
    if ($v = bv($b, 'script')) $h .= '<p class="script">' . e($v) . '</p>';
    $h .= '<h2>' . e(bv($b, 'title', '@sofi.lovefaith')) . '</h2>';
    if ($link && ($lt = bv($b, 'link_text'))) $h .= '<a class="sec-link" href="' . e($link) . '" target="_blank" rel="noopener">' . e($lt) . '</a>';
    $h .= '</div></div><div class="ig-grid">';
    foreach ($imgs as $img) {
        $h .= '<a href="' . e($link ?: '#') . '" target="_blank" rel="noopener" aria-label="Instagram"><img src="' . e(media_url($img)) . '" alt="" loading="lazy"></a>';
    }
    return $h . '</div></div>';
}

function block_image_text(array $b): string
{
    ob_start(); ?>
    <div class="wrap story <?= bd($b, 'reverse') ? 'reverse' : '' ?>">
      <?php if ($v = bd($b, 'image')): ?><div class="story-img square"><img src="<?= e(media_url($v)) ?>" alt="" loading="lazy"></div><?php endif; ?>
      <div class="story-copy">
        <?php if ($v = bv($b, 'script')): ?><p class="script lg"><?= e($v) ?></p><?php endif; ?>
        <h2><?= e(bv($b, 'title')) ?></h2>
        <?php if ($v = bv($b, 'text')): ?><p><?= nl2br(e($v)) ?></p><?php endif; ?>
        <?php if ($v = bv($b, 'cta_text')): ?><a class="btn btn-primary" href="<?= e(url(bd($b, 'cta_link', '/shop'))) ?>"><?= e($v) ?></a><?php endif; ?>
      </div>
    </div>
    <?php return (string)ob_get_clean();
}

function block_text(array $b): string
{
    $align = bd($b, 'align', 'center') === 'left' ? 'left' : 'center';
    $h = '<div class="wrap narrow text-blk ' . $align . '">';
    if ($v = bv($b, 'title')) $h .= '<h2>' . e($v) . '</h2>';
    foreach (preg_split('/\n\s*\n/', trim((string)bv($b, 'text'))) as $para) {
        if (trim($para) !== '') $h .= '<p>' . nl2br(e(trim($para))) . '</p>';
    }
    return $h . '</div>';
}

function block_video(array $b): string
{
    $u = (string)bd($b, 'url');
    if ($u === '') return '';
    $h = '<div class="wrap narrow">';
    if ($v = bv($b, 'title')) $h .= '<div class="sec-head center"><div><h2>' . e($v) . '</h2></div></div>';
    if ($emb = embed_url($u)) {
        $h .= '<div class="video-box"><iframe src="' . e($emb) . '" title="' . e(bv($b, 'title', 'Video')) . '" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>';
    } else {
        $h .= '<div class="video-box"><video src="' . e(media_url($u)) . '" controls playsinline preload="metadata"></video></div>';
    }
    return $h . '</div>';
}

function block_testimonials(array $b): string
{
    $h = '<div class="wrap">';
    if ($v = bv($b, 'title')) $h .= '<div class="sec-head center"><div><h2>' . e($v) . '</h2></div></div>';
    $h .= '<div class="quotes">';
    foreach (lines(bv($b, 'items')) as $line) {
        [$q, $who] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        $h .= '<figure class="quote"><span class="stars" aria-hidden="true">★★★★★</span><blockquote>' . e($q) . '</blockquote>' . ($who ? '<figcaption>' . e($who) . '</figcaption>' : '') . '</figure>';
    }
    return $h . '</div></div>';
}

function block_faq(array $b): string
{
    $h = '<div class="wrap narrow">';
    if ($v = bv($b, 'title')) $h .= '<div class="sec-head center"><div><h2>' . e($v) . '</h2></div></div>';
    $h .= '<div class="faq">';
    foreach (lines(bv($b, 'items')) as $line) {
        [$q, $a] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
        $h .= '<details><summary>' . e($q) . '<span aria-hidden="true">+</span></summary><p>' . e($a) . '</p></details>';
    }
    return $h . '</div></div>';
}

function block_newsletter(array $b): string
{
    ob_start(); ?>
    <div class="wrap narrow newsletter">
      <h2><?= e(bv($b, 'title')) ?></h2>
      <?php if ($v = bv($b, 'text')): ?><p><?= e($v) ?></p><?php endif; ?>
      <form method="post" action="<?= e(url('/subscribe')) ?>" class="nl-form">
        <?= csrf_field() ?>
        <label class="sr-only" for="nl-email"><?= e(t('email')) ?></label>
        <input id="nl-email" type="email" name="email" required placeholder="you@email.com">
        <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        <button class="btn btn-primary" type="submit"><?= e(bv($b, 'button', 'Subscribe')) ?></button>
      </form>
    </div>
    <?php return (string)ob_get_clean();
}

function block_page_header(array $b): string
{
    $h = '<div class="wrap page-head">';
    if ($v = bv($b, 'script')) $h .= '<p class="script lg">' . e($v) . '</p>';
    $h .= '<h1>' . e(bv($b, 'title')) . '</h1>';
    if ($v = bv($b, 'text')) $h .= '<p class="lead">' . nl2br(e($v)) . '</p>';
    return $h . '</div>';
}

function block_spacer(array $b): string
{
    return '<div style="height:' . max(0, min(400, (int)bd($b, 'height', 40))) . 'px"></div>';
}

function block_shop_grid(array $b): string
{
    return render('front/shop_grid', ['b' => $b]);
}

function block_contact(array $b): string
{
    return render('front/contact_block', ['b' => $b]);
}

/* ---------- Socials ---------- */

function socials(): array
{
    $out = [];
    foreach (['instagram', 'facebook', 'tiktok', 'pinterest', 'youtube'] as $k) {
        $u = trim((string)setting('social_' . $k, ''));
        if ($u !== '' && setting_on('social_' . $k . '_on', true)) $out[$k] = $u;
    }
    return $out;
}

function social_url(string $k): string
{
    return socials()[$k] ?? '';
}
