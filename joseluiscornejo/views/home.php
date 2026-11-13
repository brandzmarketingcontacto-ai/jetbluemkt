<?php $main = null; foreach ($products as $p) if ($p['status'] === 'disponible') { $main = $p; break; } ?>
<?php $heroBg = s('hero_bg'); $op = max(0, min(1, (float)str_replace(',', '.', s('hero_bg_opacity', '0.15')))); $blur = max(0, min(40, (int)s('hero_bg_blur', '10'))); ?>
<div class="hero-band"<?= is_preview() ? ' data-hero-band' : '' ?><?php if ($heroBg): ?> style="--hero-bg:url('<?= e(asset($heroBg)) ?>');--hero-op:<?= $op ?>;--hero-blur:<?= $blur ?>px"<?php endif; ?>>
<section id="inicio" class="wrap hero">
  <div class="hero-a stack">
    <span class="badge"><?= v('hero_badge') ?></span>
    <h1 class="h1"><?= v('hero_title') ?><br><span class="red"><?= v('hero_title_red') ?></span></h1>
    <p class="lead"><?= v('hero_text') ?></p>
    <p class="script"><?= v('hero_tagline') ?></p>
    <div class="row">
      <?php if ($main): ?>
        <form method="post" action="<?= e(url('carrito')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="pid" value="<?= (int)$main['id'] ?>">
          <input type="hidden" name="edition" value="<?= $main['price_signed'] !== null ? 'firmado' : 'estandar' ?>">
          <input type="hidden" name="buy" value="1">
          <button class="btn btn-red btn-lg" type="submit"><?= v('hero_button') ?></button>
        </form>
        <?php if ($main['amazon_url']): ?>
          <a class="btn btn-ghost btn-lg" href="<?= e($main['amazon_url']) ?>" target="_blank" rel="noopener">Ver en Amazon
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg></a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <p class="small muted"><?= v('hero_note') ?></p>
  </div>
  <div class="hero-b">
    <img class="hero-cover"<?= ea('hero_image') ?> src="<?= e(asset(s('hero_image'))) ?>" alt="Portada de <?= e(s('hero_title')) ?>, <?= e(s('hero_title_red')) ?>">
  </div>
</section>
</div>

<section class="band">
  <div class="wrap-narrow center stack">
    <p class="quote"><?= v('quote') ?></p>
    <p class="script"><?= v('site_name') ?></p>
  </div>
</section>

<section id="sobre" class="wrap section split center-y">
  <div class="split-a"><img class="about-img"<?= ea('about_image') ?> src="<?= e(asset(s('about_image'))) ?>" alt="<?= e(s('site_name')) ?>" loading="lazy"></div>
  <div class="split-b stack">
    <span class="kicker"><?= v('about_kicker') ?></span>
    <h2 class="h2"><?= v('about_title') ?></h2>
    <p class="sub"><?= v('about_subtitle') ?></p>
    <div class="lead rich"><?= vr('about_text') ?></div>
    <div class="row">
      <a class="btn btn-light" href="<?= e(url('facilitacion')) ?>">Conoce la facilitación</a>
      <a class="btn btn-ghost" href="<?= e(url('escritos')) ?>">Leer escritos</a>
    </div>
  </div>
</section>

<section id="libros" class="band">
  <div class="wrap section">
    <div class="head-row">
      <div class="stack-sm"><span class="kicker">LIBROS</span><h2 class="h2"><?= v('books_title') ?></h2></div>
      <a class="link" href="<?= e(url('libros')) ?>">Ir a la tienda →</a>
    </div>
    <div class="grid2"><?php foreach ($products as $p) book_card($p); ?></div>
  </div>
</section>

<?php require __DIR__ . '/sec_facilitacion.php'; ?>

<?php if ($posts): ?>
<section class="band">
  <div class="wrap section">
    <div class="head-row">
      <div class="stack-sm"><span class="kicker">ESCRITOS Y VIDEOS</span><h2 class="h2">Lo más reciente</h2></div>
      <a class="link" href="<?= e(url('escritos')) ?>">Ver todo →</a>
    </div>
    <div class="grid3"><?php foreach ($posts as $p) post_card($p); ?></div>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/sec_contacto.php'; ?>
