<?php
/** @var array $p @var array $media @var array $rating @var array $reviews @var array $related @var array $bundle */
$l = lang();
$name = tr($p, 'name');
$boost = array_merge(['countdown' => 1, 'lowstock' => 1, 'gift' => 1, 'whatsapp' => 1, 'bundle' => 1, 'reviews' => 1, 'sticky' => 1, 'bundle_pct' => 15], jdec($p['boosters'], []));
$options = jdec($p['options'], []);
$variants = product_variants($p);
$stock = available_stock($p, null);
$onSale = product_on_sale($p);
$save = $onSale ? (float)$p['compare_price'] - (float)$p['price'] : 0;
$sum = cart_summary();
$freeFrom = $sum['free_from'];
$cats = product_category_names($p);
$details = lines(tr($p, 'details'));
$soldOut = (int)$p['track_stock'] && !(int)$p['allow_backorder'] && $stock <= 0 && !$variants;
if (!$media) $media = [['kind' => 'image', 'path' => '', 'poster' => '', 'alt_en' => '', 'alt_es' => '']];
$variantData = array_map(fn($v) => [
    'key' => $v['key'] ?? '', 'price' => ($v['price'] ?? '') !== '' ? (float)$v['price'] : (float)$p['price'],
    'stock' => available_stock($p, $v), 'image' => ($v['image'] ?? '') ? media_url($v['image']) : '', 'name' => $v['name'] ?? '',
], $variants);
?>
<div class="wrap crumbs"><a href="<?= e(url('/')) ?>"><?= e(t('home')) ?></a> / <a href="<?= e(url('/shop')) ?>"><?= e(t('shop')) ?></a> / <span><?= e($name) ?></span></div>

<section class="wrap pdp" data-pdp data-money="<?= e(setting('currency', 'USD') === 'MXN' ? 'MX$' : '$') ?>" data-variants='<?= e(json_encode($variantData)) ?>' data-base-price="<?= e($p['price']) ?>" data-low="<?= (int)setting('low_stock_show', '8') ?>">
  <div class="gallery">
    <div class="thumbs" role="tablist" aria-label="Media">
      <?php foreach ($media as $i => $m):
        $thumb = $m['kind'] === 'video' ? ($m['poster'] ?: '') : ($m['kind'] === 'embed' ? youtube_thumb($m['path']) : $m['path']); ?>
        <button type="button" role="tab" class="thumb <?= $i === 0 ? 'on' : '' ?>" data-thumb="<?= $i ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="<?= e(($m['kind'] === 'image' ? 'Photo' : strtoupper($m['kind'])) . ' ' . ($i + 1)) ?>">
          <?php if ($thumb): ?><img src="<?= e(media_url($thumb)) ?>" alt=""><?php else: ?><span class="thumb-ph"><?= icon('video', 22) ?></span><?php endif; ?>
          <?php if (in_array($m['kind'], ['video', 'embed'], true)): ?><span class="thumb-play"><?= icon('play', 18) ?></span><?php endif; ?>
          <?php if ($m['kind'] === 'gif'): ?><span class="thumb-gif">GIF</span><?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="stage">
      <?php foreach ($media as $i => $m): $alt = ($l === 'es' ? $m['alt_es'] : $m['alt_en']) ?: $name; ?>
        <div class="slide <?= $i === 0 ? 'on' : '' ?>" data-slide="<?= $i ?>" <?= $i === 0 ? '' : 'hidden' ?>>
          <?php if ($m['kind'] === 'video'): ?>
            <video src="<?= e(media_url($m['path'])) ?>" <?= $m['poster'] ? 'poster="' . e(media_url($m['poster'])) . '"' : '' ?> controls playsinline muted loop preload="metadata"></video>
          <?php elseif ($m['kind'] === 'embed'): ?>
            <iframe data-src="<?= e(embed_url($m['path'])) ?>" title="<?= e($alt) ?>" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
          <?php elseif ($m['path']): ?>
            <img src="<?= e(media_url($m['path'])) ?>" alt="<?= e($alt) ?>" <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?> data-zoomable>
          <?php else: ?>
            <span class="p-ph"><?= icon('gift', 64) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($b = product_badge($p)): ?><span class="p-badge lg"><?= e($b) ?></span><?php endif; ?>
      <button type="button" class="zoom-btn" data-zoom aria-label="<?= e(t('zoom')) ?>"><?= icon('zoom', 18) ?></button>
    </div>
  </div>

  <div class="buybox">
    <span class="p-cat"><?= e($cats . ($p['material'] ? ' · ' . $p['material'] : '')) ?></span>
    <h1><?= e($name) ?></h1>
    <?php if ($boost['reviews'] && $rating['count']): ?>
      <a href="#reviews" class="rating-link"><span class="stars" aria-hidden="true"><?= str_repeat('★', (int)round($rating['avg'])) ?></span><span><?= e($rating['avg']) ?> · <?= e(t('based_on', ['n' => $rating['count']])) ?></span></a>
    <?php endif; ?>
    <div class="price-row">
      <span class="price" data-price><?= e(money($p['price'])) ?></span>
      <?php if ($onSale): ?><s><?= e(money($p['compare_price'])) ?></s><span class="save"><?= e(t('you_save')) ?> <?= e(money($save)) ?></span><?php endif; ?>
    </div>
    <?php if ($tl = tr($p, 'tagline')): ?><p class="tagline"><?= e($tl) ?></p><?php endif; ?>
    <div class="desc"><?= safe_html(tr($p, 'description')) ?></div>

    <?php $showCd = $boost['countdown'] && $p['sale_ends_at'] && strtotime($p['sale_ends_at']) > time();
    $showLow = $boost['lowstock'] && (int)$p['track_stock'] && $stock > 0 && $stock <= (int)setting('low_stock_show', '8');
    if ($showCd || $showLow): ?>
    <div class="urgency">
      <?php if ($showCd): ?><span class="u-label"><?= e(t('sale_ends')) ?></span><?= countdown_html($p['sale_ends_at'], 'countdown inline') ?><?php endif; ?>
      <?php if ($showLow): ?><span class="low" data-low-text><span class="dot"></span><?= e(t('only_left', ['n' => $stock])) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/cart/add')) ?>" class="buy-form" data-buy-form>
      <?= csrf_field() ?>
      <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
      <input type="hidden" name="variant" value="" data-variant-input>
      <?php foreach ($options as $oi => $opt): $oname = $opt['name_' . $l] ?? ($opt['name_en'] ?? t('option')); ?>
        <fieldset class="opt" data-opt="<?= $oi ?>">
          <legend><?= e($oname) ?>: <span data-opt-label></span></legend>
          <div class="opt-values">
          <?php foreach ($opt['values'] ?? [] as $vi => $val): $vl = $val[$l] ?? ($val['en'] ?? ''); ?>
            <label class="opt-val <?= !empty($val['color']) ? 'swatch' : '' ?>">
              <input type="radio" name="opt<?= $oi ?>" value="<?= $vi ?>" data-label="<?= e($vl) ?>" <?= $vi === 0 ? 'checked' : '' ?>>
              <?php if (!empty($val['color'])): ?><span class="sw" style="background:<?= e(preg_match('/^#[0-9a-fA-F]{3,8}$/', $val['color']) ? $val['color'] : '#ccc') ?>" title="<?= e($vl) ?>"></span><span class="sr-only"><?= e($vl) ?></span>
              <?php else: ?><span><?= e($vl) ?></span><?php endif; ?>
            </label>
          <?php endforeach; ?>
          </div>
        </fieldset>
      <?php endforeach; ?>

      <?php if ($boost['gift']): ?>
      <label class="gift">
        <input type="checkbox" data-gift-toggle>
        <span><strong><?= e(t('gift_card')) ?></strong><small><?= e(t('gift_card_hint')) ?></small></span>
      </label>
      <textarea name="gift_message" class="gift-msg" rows="2" maxlength="500" placeholder="<?= e(t('gift_message')) ?>" aria-label="<?= e(t('gift_message')) ?>" hidden data-gift-msg></textarea>
      <?php endif; ?>

      <div class="qty-row">
        <div class="qty" data-qty>
          <button type="button" data-dec aria-label="−"><?= icon('minus', 18) ?></button>
          <input type="number" name="qty" value="1" min="1" max="99" aria-label="<?= e(t('quantity')) ?>">
          <button type="button" data-inc aria-label="+"><?= icon('plus', 18) ?></button>
        </div>
        <button class="btn btn-primary grow" type="submit" data-add <?= $soldOut ? 'disabled' : '' ?>><?= e($soldOut ? t('out_of_stock') : t('add_to_cart')) ?></button>
      </div>
      <?php if (stripe_configured() && !$soldOut): ?>
        <button class="btn btn-dark block" type="submit" name="buy_now" value="1"><?= icon('lock', 18) ?><?= e(t('buy_now')) ?></button>
      <?php endif; ?>
    </form>
    <?php if ($boost['whatsapp']): ?>
      <a class="btn btn-wa block" target="_blank" rel="noopener" href="<?= e(wa_link(($l === 'es' ? 'Hola, me interesa: ' : 'Hi! I\'d like: ') . $name . ' — ' . product_url($p))) ?>"><?= icon('whatsapp', 18) ?><?= e(t('order_whatsapp')) ?></a>
    <?php endif; ?>

    <?php if ($freeFrom > 0): $after = max(0, $sum['subtotal'] - $sum['discount']); $pct = min(100, (int)round($after / $freeFrom * 100)); ?>
    <div class="ship-progress">
      <span><?= $after >= $freeFrom ? e(t('free_ship_ok')) : t('free_ship_away', ['amt' => '<strong>' . e(money($freeFrom - $after)) . '</strong>']) ?></span>
      <div class="bar"><div style="width: <?= max(4, $pct) ?>%"></div></div>
    </div>
    <?php endif; ?>

    <div class="trust">
      <span><?= icon('shield', 22) ?><?= e(t('wont_tarnish')) ?></span>
      <span><?= icon('truck', 22) ?><?= e(t('ships_fast')) ?></span>
      <span><?= icon('return', 22) ?><?= e(t('returns')) ?></span>
    </div>
    <p class="secure-note"><?= icon('lock', 16) ?><?= e(t('secure_stripe')) ?> · Visa · Mastercard · Amex · Apple Pay · Google Pay</p>

    <div class="accordion">
      <?php if ($details): ?>
      <details open><summary><?= e(t('details')) ?><span aria-hidden="true">+</span></summary><ul><?php foreach ($details as $d): ?><li><?= e($d) ?></li><?php endforeach; ?></ul></details>
      <?php endif; ?>
      <?php if ($c = st('care_text')): ?><details><summary><?= e(t('care')) ?><span aria-hidden="true">+</span></summary><p><?= nl2br(e($c)) ?></p></details><?php endif; ?>
      <?php if ($c = st('shipping_text')): ?><details><summary><?= e(t('shipping_returns')) ?><span aria-hidden="true">+</span></summary><p><?= nl2br(e($c)) ?></p></details><?php endif; ?>
    </div>
  </div>
</section>

<?php if ($bundle): $all = array_merge([$p], $bundle); $tot = array_sum(array_map(fn($x) => (float)$x['price'], $all)); $pct = (int)$boost['bundle_pct']; ?>
<section class="blk bg-blush pad-m">
  <div class="wrap bundle">
    <div><p class="script lg"><?= e(t('complete_look')) ?></p><h2><?= e(t('bundle_save', ['p' => $pct])) ?></h2></div>
    <form method="post" action="<?= e(url('/cart/add')) ?>" class="bundle-items">
      <?= csrf_field() ?>
      <input type="hidden" name="bundle_pct" value="<?= $pct ?>">
      <?php foreach ($all as $i => $bp): ?>
        <?php if ($i): ?><span class="plus" aria-hidden="true">+</span><?php endif; ?>
        <a href="<?= e(product_url($bp)) ?>" class="b-item"><img src="<?= e(media_url(product_cover($bp))) ?>" alt="<?= e(tr($bp, 'name')) ?>" loading="lazy"><span><?= e(tr($bp, 'name')) ?></span></a>
        <input type="hidden" name="bundle[]" value="<?= (int)$bp['id'] ?>">
      <?php endforeach; ?>
      <div class="b-total">
        <span><strong><?= e(money($tot * (1 - $pct / 100))) ?></strong> <s><?= e(money($tot)) ?></s></span>
        <button class="btn btn-primary" type="submit"><?= e(t('bundle_add')) ?></button>
      </div>
    </form>
  </div>
</section>
<?php endif; ?>

<?php if ($boost['reviews']): ?>
<section class="blk pad-l" id="reviews">
  <div class="wrap reviews">
    <div class="rv-summary">
      <h2><?= e(t('reviews')) ?></h2>
      <?php if ($rating['count']): ?>
        <span class="rv-avg"><?= e($rating['avg']) ?></span>
        <span class="stars" aria-label="<?= e($rating['avg']) ?>/5"><?= str_repeat('★', (int)round($rating['avg'])) ?></span>
        <span class="muted"><?= e(t('based_on', ['n' => $rating['count']])) ?></span>
      <?php else: ?>
        <p class="muted"><?= e(t('no_reviews')) ?></p>
      <?php endif; ?>
      <details class="rv-form">
        <summary class="btn btn-outline"><?= e(t('write_review')) ?></summary>
        <form method="post" action="<?= e(url('/review')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
          <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <label><?= e(t('name')) ?><input name="name" required maxlength="120"></label>
          <label><?= e(t('email')) ?><input name="email" type="email" maxlength="190"></label>
          <label><?= e(t('rating')) ?><select name="rating"><?php for ($r = 5; $r >= 1; $r--): ?><option value="<?= $r ?>"><?= str_repeat('★', $r) ?></option><?php endfor; ?></select></label>
          <label><?= e(t('review_title')) ?><input name="title" maxlength="190"></label>
          <label><?= e(t('review_body')) ?><textarea name="body" rows="4" required maxlength="3000"></textarea></label>
          <button class="btn btn-primary" type="submit"><?= e(t('submit_review')) ?></button>
        </form>
      </details>
    </div>
    <div class="rv-list">
      <?php foreach ($reviews as $r): ?>
        <article class="rv">
          <span class="stars" aria-label="<?= (int)$r['rating'] ?>/5"><?= str_repeat('★', (int)$r['rating']) ?></span>
          <?php if ($r['title']): ?><strong><?= e($r['title']) ?></strong><?php endif; ?>
          <p><?= nl2br(e($r['body'])) ?></p>
          <span class="muted"><?= e($r['name']) ?> · <?= e(t('verified')) ?></span>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($related): ?>
<section class="blk pad-m">
  <div class="wrap">
    <div class="sec-head"><div><h2><?= e(t('may_love')) ?></h2></div></div>
    <div class="p-grid"><?php foreach ($related as $r) echo product_card($r); ?></div>
  </div>
</section>
<?php endif; ?>

<?php if ($boost['sticky'] && !$soldOut): ?>
<div class="sticky-buy" data-sticky-buy>
  <span><strong><?= e($name) ?></strong><span data-price><?= e(money($p['price'])) ?></span></span>
  <button class="btn btn-primary" type="button" data-sticky-add><?= e(t('add_to_cart')) ?></button>
</div>
<?php endif; ?>

<div class="lightbox" data-lightbox hidden><button type="button" aria-label="Close" data-lightbox-close><?= icon('close', 24) ?></button><img alt=""></div>
