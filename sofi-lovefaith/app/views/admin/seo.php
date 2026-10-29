<?php /** @var array $pages */
$s = fn($k, $d = '') => (string)setting($k, $d);
$on = fn($k) => setting_on($k, true) ? 'checked' : '';
$domain = $s('site_url') ?: rtrim(abs_url('/'), '/');
$host = preg_replace('#^https?://#', '', $domain);
$logo = media_url(brand_logo());
$custom = $s('og_image') ? media_url($s('og_image')) : '';
?>
<form method="post" class="seo-page" data-seo data-dirty-watch>
  <?= csrf_field() ?>
  <div class="page-head">
    <div><h1>SEO y Open Graph</h1><p class="muted">Cómo aparece tu tienda en Google y al compartir el enlace en WhatsApp, Instagram o Facebook.</p></div>
    <button class="btn btn-primary" type="submit">Guardar SEO</button>
  </div>

  <div class="seo-cols">
    <div class="stack">
      <section class="card">
        <div class="card-head"><h2>Datos del sitio</h2><div class="seg small" role="tablist"><button type="button" class="on" data-lang-tab="en">English</button><button type="button" data-lang-tab="es">Español</button></div></div>
        <label class="fld">Dominio principal<input type="url" name="site_url" value="<?= e($s('site_url')) ?>" placeholder="https://tu-dominio.com" data-seo-domain></label>
        <?php foreach (['en', 'es'] as $l): ?>
          <div class="lang-pane" data-lang-pane="<?= $l ?>" <?= $l === 'es' ? 'hidden' : '' ?>>
            <label class="fld">Título (pestaña y Google) <small class="muted" data-count-for="seo_title_<?= $l ?>"></small><input type="text" name="seo_title_<?= $l ?>" value="<?= e($s('seo_title_' . $l)) ?>" maxlength="120" data-counter="60" data-seo-title="<?= $l ?>"></label>
            <label class="fld">Descripción <small class="muted" data-count-for="seo_desc_<?= $l ?>"></small><textarea name="seo_desc_<?= $l ?>" rows="3" maxlength="300" data-counter="160" data-seo-desc="<?= $l ?>"><?= e($s('seo_desc_' . $l)) ?></textarea></label>
            <label class="fld">Palabras clave<input type="text" name="seo_keywords_<?= $l ?>" value="<?= e($s('seo_keywords_' . $l)) ?>"></label>
            <label class="fld">Título al compartir (og:title)<input type="text" name="og_title_<?= $l ?>" value="<?= e($s('og_title_' . $l)) ?>" data-og-title="<?= $l ?>"></label>
            <label class="fld">Descripción al compartir (og:description) <small class="muted">vacío = la descripción de arriba</small><textarea name="og_desc_<?= $l ?>" rows="2" data-og-desc="<?= $l ?>"><?= e($s('og_desc_' . $l)) ?></textarea></label>
          </div>
        <?php endforeach; ?>
        <label class="fld">Plantilla de título para páginas internas<input type="text" name="seo_title_template" value="<?= e($s('seo_title_template', '%page% | %site%')) ?>" class="mono"><small class="muted">%page% = nombre de la página o producto · %site% = nombre de la tienda</small></label>
      </section>

      <section class="card">
        <h2>Open Graph — imagen al compartir</h2>
        <p class="muted">Se usa en WhatsApp, Facebook, Instagram (mensajes), Messenger y X. Tamaño 1200 × 630.</p>
        <div class="og-modes" role="radiogroup" aria-label="Imagen al compartir">
          <?php foreach (['logo' => ['Logotipo del sitio', 'Tu logo sobre el color de la marca (recomendado)'], 'custom' => ['Imagen personalizada', 'Sube una foto 1200 × 630'], 'auto' => ['Automática por página', 'Cada producto usa su foto de portada']] as $k => [$t, $h]): ?>
            <label class="og-mode"><input type="radio" name="og_mode" value="<?= $k ?>" <?= $s('og_mode', 'logo') === $k ? 'checked' : '' ?> data-og-mode><span><strong><?= $t ?></strong><small class="muted"><?= $h ?></small></span></label>
          <?php endforeach; ?>
        </div>
        <div class="grid-2 tight">
          <div class="fld"><span>Fondo de la tarjeta con logo</span>
            <div class="row-gap">
              <?php foreach (['cream' => '#FDF7F5', 'blush' => '#F8E1E7', 'plum' => '#4B2635'] as $k => $c): ?>
                <label class="swatch-radio"><input type="radio" name="og_bg" value="<?= $k ?>" <?= $s('og_bg', 'cream') === $k ? 'checked' : '' ?> data-og-bg="<?= $c ?>"><span style="background: <?= $c ?>"></span><span class="sr-only"><?= $k ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="img-field" data-image-field>
            <span>Imagen personalizada</span>
            <input type="hidden" name="og_image" value="<?= e($s('og_image')) ?>" data-og-custom>
            <div class="row-gap"><button type="button" class="btn btn-ghost sm" data-pick="image"><?= icon('upload', 14) ?>Subir o elegir 1200 × 630</button><button type="button" class="btn btn-ghost sm" data-clear>Quitar</button></div>
          </div>
          <label class="fld">Tarjeta de X / Twitter<select name="twitter_card"><option value="summary_large_image" <?= $s('twitter_card') === 'summary_large_image' ? 'selected' : '' ?>>Imagen grande</option><option value="summary" <?= $s('twitter_card') === 'summary' ? 'selected' : '' ?>>Resumen</option></select></label>
          <div class="fld"><span>Imagen generada</span><a href="<?= e(url('/og-image.jpg')) ?>" target="_blank" rel="noopener">Ver /og-image.jpg ↗</a></div>
        </div>
      </section>

      <section class="card flush">
        <div class="card-head pad"><h2>SEO por página</h2><span class="muted small">Cada producto tiene su SEO en <a href="<?= e(url('/admin/products')) ?>">Editar producto</a></span></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Página</th><th>Título (EN / ES)</th><th>Descripción (EN / ES)</th></tr></thead>
            <tbody>
              <?php foreach ($pages as $pg): $ps = jdec($pg['seo'], []); ?>
                <tr>
                  <td><strong><?= e($pg['title_es']) ?></strong><br><small class="muted">/<?= $pg['slug'] === 'home' ? '' : e($pg['slug']) ?></small></td>
                  <td><input type="text" name="page_seo[<?= e($pg['slug']) ?>][title_en]" value="<?= e($ps['title_en'] ?? '') ?>" placeholder="<?= $pg['slug'] === 'home' ? 'Usa el título del sitio' : e($pg['title_en']) ?>" aria-label="Título en inglés"><input type="text" name="page_seo[<?= e($pg['slug']) ?>][title_es]" value="<?= e($ps['title_es'] ?? '') ?>" placeholder="<?= $pg['slug'] === 'home' ? 'Usa el título del sitio' : e($pg['title_es']) ?>" aria-label="Título en español"></td>
                  <td><textarea name="page_seo[<?= e($pg['slug']) ?>][desc_en]" rows="2" placeholder="Usa la descripción del sitio" aria-label="Descripción en inglés"><?= e($ps['desc_en'] ?? '') ?></textarea><textarea name="page_seo[<?= e($pg['slug']) ?>][desc_es]" rows="2" placeholder="Usa la descripción del sitio" aria-label="Descripción en español"><?= e($ps['desc_es'] ?? '') ?></textarea></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <div class="grid-2">
        <section class="card">
          <h2>Datos del negocio</h2>
          <p class="muted">Datos estructurados (schema.org) para Google. El teléfono y las redes se toman de Ajustes.</p>
          <dl class="facts"><div><dt>Teléfono</dt><dd><?= e(wa_display()) ?></dd></div><div><dt>Instagram</dt><dd class="ell"><?= e(social_url('instagram') ?: '—') ?></dd></div></dl>
          <div class="grid-2 tight">
            <label class="fld">País<input type="text" name="biz_country" value="<?= e($s('biz_country', 'US')) ?>" placeholder="US"></label>
            <label class="fld">Rango de precios<input type="text" name="biz_price_range" value="<?= e($s('biz_price_range', '$$')) ?>"></label>
          </div>
        </section>
        <section class="card">
          <h2>Técnico y medición</h2>
          <label class="switch"><input type="checkbox" name="seo_sitemap" value="1" <?= $on('seo_sitemap') ?>><span></span>sitemap.xml automático</label>
          <label class="switch"><input type="checkbox" name="seo_index" value="1" <?= $on('seo_index') ?>><span></span>Permitir que Google indexe el sitio</label>
          <label class="switch"><input type="checkbox" name="seo_hreflang" value="1" <?= $on('seo_hreflang') ?>><span></span>Etiquetas de idioma (hreflang EN/ES)</label>
          <label class="fld">Google Search Console (código de verificación)<input type="text" name="gsc_verify" value="<?= e($s('gsc_verify')) ?>" class="mono" placeholder="google-site-verification=…"></label>
          <div class="grid-2 tight">
            <label class="fld">Google Analytics<input type="text" name="ga_id" value="<?= e($s('ga_id')) ?>" class="mono" placeholder="G-XXXXXXX"></label>
            <label class="fld">Meta Pixel<input type="text" name="pixel_id" value="<?= e($s('pixel_id')) ?>" class="mono" placeholder="ID del píxel"></label>
          </div>
          <p class="hint"><a href="<?= e(url('/sitemap.xml')) ?>" target="_blank" rel="noopener">/sitemap.xml</a> · <a href="<?= e(url('/robots.txt')) ?>" target="_blank" rel="noopener">/robots.txt</a></p>
        </section>
      </div>
    </div>

    <aside class="seo-previews" aria-label="Vistas previas">
      <div class="seg" role="tablist">
        <button type="button" class="on" data-prev-tab="wa">WhatsApp</button>
        <button type="button" data-prev-tab="fb">Facebook</button>
        <button type="button" data-prev-tab="google">Google</button>
      </div>
      <div class="prev prev-wa" data-prev="wa">
        <div class="wa-bubble">
          <div class="wa-card">
            <div class="og-img" data-og-img><img src="<?= e($logo) ?>" alt="" class="og-logo" data-og-logo><img src="<?= e($custom) ?>" alt="" class="og-custom" data-og-custom-img <?= $custom ? '' : 'hidden' ?>></div>
            <div class="wa-meta"><strong data-prev-title></strong><span data-prev-desc></span><small><?= e($host) ?></small></div>
          </div>
          <span class="wa-link"><?= e($domain) ?></span><span class="wa-time">10:42 ✓✓</span>
        </div>
      </div>
      <div class="prev prev-fb" data-prev="fb" hidden>
        <div class="og-img" data-og-img><img src="<?= e($logo) ?>" alt="" class="og-logo" data-og-logo><img src="<?= e($custom) ?>" alt="" class="og-custom" data-og-custom-img <?= $custom ? '' : 'hidden' ?>></div>
        <div class="fb-meta"><small><?= e(strtoupper($host)) ?></small><strong data-prev-title></strong><span data-prev-desc></span></div>
      </div>
      <div class="prev prev-google" data-prev="google" hidden>
        <span class="g-site"><img src="<?= e(media_url(brand_favicon())) ?>" alt=""><span><span><?= e($s('store_name', 'Sofi Love & Faith')) ?></span><small><?= e($domain) ?></small></span></span>
        <span class="g-title" data-prev-gtitle></span>
        <span class="g-desc" data-prev-gdesc></span>
      </div>
      <p class="hint">Facebook guarda la vista previa en caché. Después de cambiarla, usa el <a href="https://developers.facebook.com/tools/debug/" target="_blank" rel="noopener">depurador de Facebook</a>.</p>
    </aside>
  </div>
</form>
