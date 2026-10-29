<?php
/** @var array $page @var array $pages @var array $cats */
$paths = ['home' => '/', 'shop' => '/shop', 'contact' => '/contact'];
$boot = [
    'slug' => $page['slug'],
    'path' => $paths[$page['slug']] ?? '/',
    'blocks' => jdec($page['blocks_draft'] ?: $page['blocks'], []),
    'hasDraft' => (bool)$page['blocks_draft'],
    'seo' => jdec($page['seo'], []) + ['title_en' => '', 'title_es' => '', 'desc_en' => '', 'desc_es' => ''],
    'schemas' => block_schemas(),
    'categories' => $cats,
    'previewUrl' => url(($paths[$page['slug']] ?? '/')) . '?preview=draft',
    'saveUrl' => url('/admin/api/page'),
];
?>
<div class="builder" id="builder">
  <div class="b-top">
    <div class="seg" role="tablist" aria-label="Página">
      <?php foreach ($pages as $pg): ?>
        <a role="tab" href="<?= e(url('/admin/pages?slug=' . $pg['slug'])) ?>" class="<?= $pg['slug'] === $page['slug'] ? 'on' : '' ?>" aria-selected="<?= $pg['slug'] === $page['slug'] ? 'true' : 'false' ?>"><?= e($pg['title_es']) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="seg small" role="group" aria-label="Idioma del contenido">
      <button type="button" data-lang="en" class="on">EN</button>
      <button type="button" data-lang="es">ES</button>
    </div>
    <div class="seg icons" role="group" aria-label="Vista previa">
      <button type="button" data-device="desktop" class="on" aria-label="Escritorio"><?= icon('desktop', 18) ?></button>
      <button type="button" data-device="tablet" aria-label="Tableta"><?= icon('tablet', 18) ?></button>
      <button type="button" data-device="mobile" aria-label="Móvil"><?= icon('mobile', 18) ?></button>
    </div>
    <div class="seg icons">
      <button type="button" data-undo aria-label="Deshacer" title="Deshacer (Ctrl+Z)">↶</button>
      <button type="button" data-redo aria-label="Rehacer" title="Rehacer">↷</button>
    </div>
    <span class="b-status" data-status><span class="dot"></span>Sin cambios</span>
    <a class="btn btn-ghost sm" href="<?= e($boot['previewUrl']) ?>" target="_blank" rel="noopener"><?= icon('external', 16) ?>Vista previa</a>
    <button class="btn btn-primary sm" type="button" data-publish><?= icon('check', 16) ?>Publicar cambios</button>
  </div>
  <div class="b-cols">
    <section class="b-left" aria-label="Bloques">
      <div class="b-sec-head"><h2>Estructura</h2><span class="muted" data-count></span></div>
      <ol class="b-list" data-list></ol>
      <button type="button" class="b-pageset" data-page-settings><?= icon('seo', 16) ?>SEO de esta página</button>
      <div class="b-sec-head"><h3>Añadir bloque</h3></div>
      <div class="b-lib" data-lib></div>
      <p class="hint">La barra de ofertas en movimiento se edita en <a href="<?= e(url('/admin/offers')) ?>">Ofertas</a>; logo, menú y pie en <a href="<?= e(url('/admin/settings')) ?>">Ajustes</a>.</p>
    </section>
    <section class="b-canvas" aria-label="Vista previa">
      <div class="frame-wrap" data-frame-wrap>
        <iframe title="Vista previa de la página" data-frame src="<?= e($boot['previewUrl']) ?>"></iframe>
      </div>
    </section>
    <section class="b-right" aria-label="Propiedades" data-inspector></section>
  </div>
</div>
<script type="application/json" id="builder-data"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(url('/assets/js/builder.js')) ?>?v=<?= APP_VERSION ?>" defer></script>
