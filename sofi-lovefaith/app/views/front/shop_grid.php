<?php
/** @var array $b block */
$cat = (string)($_GET['cat'] ?? '');
$qstr = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? '');
$sale = !empty($_GET['sale']);
$max = (int)($_GET['max'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 12;
$opts = ['cat' => $cat, 'q' => $qstr, 'sort' => $sort, 'sale' => $sale, 'max' => $max];
$total = count(products_list($opts));
$list = products_list($opts + ['limit' => $per, 'offset' => ($page - 1) * $per]);
$pages = (int)ceil($total / $per);
$cats = rows('SELECT * FROM categories ORDER BY sort, id');
$maxPrice = (int)ceil((float)val('SELECT MAX(price) FROM products WHERE status = "published"') ?: 100);
$qs = function (array $change) use ($cat, $qstr, $sort, $sale, $max) {
    $p = array_filter(['cat' => $cat, 'q' => $qstr, 'sort' => $sort, 'sale' => $sale ? 1 : '', 'max' => $max ?: ''] , fn($v) => $v !== '' && $v !== null);
    $p = array_filter(array_merge($p, $change), fn($v) => $v !== '' && $v !== null);
    return url('/shop') . ($p ? '?' . http_build_query($p) : '');
};
?>
<div class="shop-hero">
  <div class="wrap shop-hero-row">
    <div>
      <p class="crumbs-inline"><a href="<?= e(url('/')) ?>"><?= e(t('home')) ?></a> / <?= e(t('shop')) ?></p>
      <h1><?= e(bv($b, 'title', t('shop'))) ?></h1>
      <?php if ($v = bv($b, 'text')): ?><p class="lead"><?= e($v) ?></p><?php endif; ?>
    </div>
    <?php if ($v = bv($b, 'promo_big')): ?>
      <a class="promo-chip" href="<?= e($qs(['sale' => 1, 'page' => ''])) ?>"><span class="big"><?= e($v) ?></span><span><?= nl2br(e(bv($b, 'promo_text'))) ?></span></a>
    <?php endif; ?>
  </div>
</div>

<div class="wrap chips" role="navigation" aria-label="Categories">
  <a href="<?= e($qs(['cat' => '', 'page' => ''])) ?>" class="chip <?= $cat === '' ? 'on' : '' ?>" <?= $cat === '' ? 'aria-current="true"' : '' ?>><?= e(t('all')) ?></a>
  <?php foreach ($cats as $c): ?>
    <a href="<?= e($qs(['cat' => $c['slug'], 'page' => ''])) ?>" class="chip <?= $cat === $c['slug'] ? 'on' : '' ?>" <?= $cat === $c['slug'] ? 'aria-current="true"' : '' ?>><?= e(tr($c, 'name')) ?></a>
  <?php endforeach; ?>
</div>

<div class="wrap shop-layout">
  <form class="filters" method="get" action="<?= e(url('/shop')) ?>" aria-label="Filters">
    <?php if ($cat): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
    <label class="f-block" id="search"><span class="f-label"><?= e(t('search')) ?></span>
      <span class="search-in"><?= icon('search', 16) ?><input type="search" name="q" value="<?= e($qstr) ?>" placeholder="<?= e(t('search_ph')) ?>"></span>
    </label>
    <label class="f-block"><span class="f-label"><?= e(t('sort')) ?></span>
      <select name="sort" data-autosubmit>
        <?php foreach (['' => 'sort_best', 'new' => 'sort_new', 'low' => 'sort_low', 'high' => 'sort_high', 'disc' => 'sort_disc'] as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e(t($lbl)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="f-block"><span class="f-label" data-range-label="<?= e(t('price_up_to', ['amt' => '%'])) ?>"><?= e(t('price_up_to', ['amt' => money($max ?: $maxPrice)])) ?></span>
      <input type="range" name="max" min="5" max="<?= $maxPrice ?>" step="1" value="<?= $max ?: $maxPrice ?>" data-range>
    </label>
    <label class="switch-row"><?= e(t('on_sale_only')) ?><input type="checkbox" name="sale" value="1" <?= $sale ? 'checked' : '' ?> data-autosubmit></label>
    <button class="btn btn-primary block" type="submit"><?= e(t('apply')) ?></button>
    <a class="help-card" href="<?= e(wa_link()) ?>" target="_blank" rel="noopener">
      <strong><?= e(t('help_gift')) ?></strong><span><?= e(t('help_gift_text')) ?></span><span class="wa-text"><?= e(wa_display()) ?> →</span>
    </a>
  </form>

  <div class="results">
    <p class="muted"><?= e(t('showing', ['n' => $total])) ?></p>
    <?php if ($list): ?>
      <div class="p-grid"><?php foreach ($list as $p) echo product_card($p); ?></div>
    <?php else: ?>
      <p class="empty"><?= e(t('no_products')) ?></p>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Pagination">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
          <a href="<?= e($qs(['page' => $i > 1 ? $i : ''])) ?>" class="<?= $i === $page ? 'on' : '' ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  </div>
</div>
