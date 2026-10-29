<?php
/** @var ?array $p @var array $media @var array $cats @var array $all @var array $covers */
$v = fn($k, $d = '') => $p[$k] ?? $d;
$boost = array_merge(['countdown' => 1, 'lowstock' => 1, 'gift' => 1, 'whatsapp' => 1, 'bundle' => 1, 'reviews' => 1, 'sticky' => 1, 'bundle_pct' => 15], jdec($p['boosters'] ?? '', []));
$seo = jdec($p['seo'] ?? '', []) + ['title_en' => '', 'title_es' => '', 'desc_en' => '', 'desc_es' => ''];
$pcats = array_filter(explode(',', (string)$v('categories')));
$bundleIds = array_filter(array_map('intval', explode(',', (string)$v('bundle_ids'))));
$boot = [
    'media' => array_map(fn($m) => ['path' => $m['path'], 'url' => $m['kind'] === 'embed' ? $m['path'] : media_url($m['path']), 'kind' => $m['kind'], 'poster' => (string)$m['poster'], 'posterUrl' => media_url($m['poster']), 'alt_en' => $m['alt_en'], 'alt_es' => $m['alt_es'], 'thumb' => $m['kind'] === 'embed' ? youtube_thumb($m['path']) : ''], $media),
    'options' => jdec($p['options'] ?? '', []),
    'variants' => jdec($p['variants'] ?? '', []),
    'uploadUrl' => url('/admin/api/upload'),
    'money' => setting('currency', 'USD') === 'MXN' ? 'MX$' : '$',
    'siteUrl' => rtrim(setting('site_url', '') ?: abs_url('/'), '/'),
];
$boosters = [
    'countdown' => ['Cuenta regresiva de la oferta', 'Usa la fecha de fin de oferta de arriba'],
    'lowstock' => ['“Solo quedan X”', 'Se muestra con stock ≤ ' . (int)setting('low_stock_show', '8')],
    'gift' => ['Tarjeta de regalo', 'Casilla gratis para escribir un mensaje'],
    'whatsapp' => ['Botón “Pedir por WhatsApp”', 'Envía el nombre del producto'],
    'reviews' => ['Reseñas de clientes', 'Se aprueban en Formularios › Reseñas'],
    'sticky' => ['Botón de compra fijo en móvil', 'Siempre visible al hacer scroll'],
    'bundle' => ['“Completa el look”', 'Paquete con descuento (elige productos abajo)'],
];
$types = ['Necklace', 'Bracelet', 'Ring', 'Earrings', 'Rosary', 'Perfume', 'Gift set', 'Accessory'];
?>
<form method="post" class="pe" id="product-form" data-dirty-watch>
  <?= csrf_field() ?>
  <input type="hidden" name="media_json" data-media-json>
  <input type="hidden" name="options_json" data-options-json>
  <input type="hidden" name="variants_json" data-variants-json>
  <input type="hidden" name="_tab" value="" data-tab-field>

  <div class="pe-top">
    <a class="icon-btn bordered" href="<?= e(url('/admin/products')) ?>" aria-label="Volver a productos">←</a>
    <div class="pe-title"><small class="muted">Productos / <?= $p ? 'Editar' : 'Nuevo' ?></small><strong data-live-name><?= e($v('name_es') ?: 'Nuevo producto') ?></strong></div>
    <span class="st st-<?= e($v('status', 'draft')) ?>"><?= e(['published' => 'Publicado', 'draft' => 'Borrador', 'hidden' => 'Oculto'][$v('status', 'draft')] ?? '') ?></span>
    <span class="dirty muted" data-dirty hidden>Cambios sin guardar</span>
    <?php if ($p): ?><a class="btn btn-ghost sm" href="<?= e(product_url($p)) ?>" target="_blank" rel="noopener">Ver en tienda <?= icon('external', 14) ?></a><?php endif; ?>
    <button class="btn btn-primary sm" type="submit"><?= icon('check', 16) ?>Guardar producto</button>
  </div>

  <div class="pe-cols">
    <div class="pe-main">
      <section class="card pe-score">
        <div class="ring" data-score-ring><span data-score>0%</span></div>
        <div><strong data-score-title>Completa tu producto</strong><div class="chips" data-score-chips></div></div>
      </section>

      <!-- Info -->
      <section class="card" id="info">
        <div class="card-head">
          <h2>Información del producto</h2>
          <div class="seg small" role="tablist" aria-label="Idioma"><button type="button" class="on" data-lang-tab="en">English</button><button type="button" data-lang-tab="es">Español</button></div>
        </div>
        <?php foreach (['en' => 'inglés', 'es' => 'español'] as $l => $lname): ?>
        <div class="lang-pane" data-lang-pane="<?= $l ?>" <?= $l === 'es' ? 'hidden' : '' ?>>
          <label class="fld">Nombre del producto (<?= $lname ?>)<input type="text" name="name_<?= $l ?>" value="<?= e($v('name_' . $l)) ?>" maxlength="190" class="big" data-name-<?= $l ?> <?= $l === 'en' ? 'data-slug-source' : '' ?>></label>
          <label class="fld">Frase corta (aparece junto al precio)<input type="text" name="tagline_<?= $l ?>" value="<?= e($v('tagline_' . $l)) ?>" maxlength="255"></label>
          <div class="fld">
            <span>Descripción</span>
            <div class="rte">
              <div class="rte-bar" role="toolbar" aria-label="Formato">
                <button type="button" data-cmd="bold" aria-label="Negrita"><b>B</b></button>
                <button type="button" data-cmd="italic" aria-label="Cursiva"><i>I</i></button>
                <button type="button" data-cmd="underline" aria-label="Subrayado"><u>U</u></button>
                <button type="button" data-cmd="insertUnorderedList" aria-label="Lista">• Lista</button>
                <button type="button" data-cmd="createLink" aria-label="Enlace">Enlace</button>
                <button type="button" data-cmd="removeFormat" aria-label="Quitar formato">Limpiar</button>
                <?php if ($l === 'es'): ?><button type="button" class="rte-copy" data-copy-from="en">Copiar desde inglés</button><?php else: ?><button type="button" class="rte-copy" data-copy-from="es">Copiar desde español</button><?php endif; ?>
              </div>
              <div class="rte-area" contenteditable="true" data-rte="description_<?= $l ?>"><?= safe_html($v('description_' . $l)) ?></div>
              <textarea name="description_<?= $l ?>" hidden><?= e($v('description_' . $l)) ?></textarea>
            </div>
          </div>
          <div class="grid-2 tight">
            <label class="fld">Detalles (uno por línea)<textarea name="details_<?= $l ?>" rows="4" placeholder="Material: acero inoxidable 316L&#10;Hipoalergénico"><?= e($v('details_' . $l)) ?></textarea></label>
            <label class="fld">Etiqueta en la foto (opcional)<input type="text" name="badge_<?= $l ?>" value="<?= e($v('badge_' . $l)) ?>" maxlength="40" placeholder="<?= $l === 'es' ? 'Nuevo, Más vendido…' : 'New, Best seller…' ?>"><small class="muted">Si la dejas vacía y hay oferta, se muestra el % de descuento.</small></label>
          </div>
        </div>
        <?php endforeach; ?>
      </section>

      <!-- Media -->
      <section class="card" id="media">
        <div class="card-head">
          <div><h2>Fotos, videos y GIFs</h2><p class="muted">Arrastra para ordenar. La primera es la portada en la tienda.</p></div>
          <div class="row-gap">
            <button type="button" class="btn btn-ghost sm" data-media-library><?= icon('folder', 16) ?>Biblioteca</button>
            <button type="button" class="btn btn-ghost sm" data-media-url><?= icon('video', 16) ?>Video de YouTube / Vimeo</button>
          </div>
        </div>
        <div class="media-grid" data-media-grid></div>
        <label class="dropzone" data-dropzone>
          <input type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime" data-file-input hidden>
          <?= icon('upload', 30) ?>
          <strong>Arrastra aquí tus fotos, videos o GIFs</strong>
          <span class="muted">o haz clic para elegir · varios a la vez · JPG, PNG, WEBP, GIF, MP4</span>
        </label>
        <div class="upload-progress" data-upload-progress hidden><div></div><span></span></div>
        <div class="media-detail" data-media-detail hidden></div>
      </section>

      <!-- Price -->
      <section class="card" id="price">
        <h2>Precio y oferta</h2>
        <div class="grid-3">
          <label class="fld">Precio de venta<span class="money"><i><?= e($boot['money']) ?></i><input type="text" inputmode="decimal" name="price" value="<?= e($p ? number_format((float)$p['price'], 2, '.', '') : '') ?>" data-price required></span></label>
          <label class="fld">Precio antes (tachado)<span class="money"><i><?= e($boot['money']) ?></i><input type="text" inputmode="decimal" name="compare_price" value="<?= e($p && $p['compare_price'] !== null ? number_format((float)$p['compare_price'], 2, '.', '') : '') ?>" data-compare></span></label>
          <label class="fld">Costo (privado)<span class="money"><i><?= e($boot['money']) ?></i><input type="text" inputmode="decimal" name="cost" value="<?= e($p && $p['cost'] !== null ? number_format((float)$p['cost'], 2, '.', '') : '') ?>" data-cost></span></label>
        </div>
        <div class="chips" data-price-chips></div>
        <label class="fld half">La oferta termina el (opcional)<input type="datetime-local" name="sale_ends_at" value="<?= e($v('sale_ends_at') ? date('Y-m-d\TH:i', strtotime($v('sale_ends_at'))) : '') ?>"><small class="muted">Activa la cuenta regresiva en la página del producto.</small></label>
      </section>

      <!-- Variants -->
      <section class="card" id="variants">
        <div class="card-head"><div><h2>Variantes</h2><p class="muted">Color, largo, talla… Cada combinación puede tener su precio, stock y foto.</p></div><button type="button" class="btn btn-ghost sm" data-add-option><?= icon('plus', 16) ?>Añadir opción</button></div>
        <div data-options></div>
        <div class="table-wrap" data-variants-wrap hidden>
          <table class="table compact"><thead><tr><th>Variante</th><th>Foto</th><th>Precio</th><th>Stock</th><th>SKU</th></tr></thead><tbody data-variants></tbody></table>
        </div>
      </section>

      <!-- Inventory -->
      <section class="card" id="inventory">
        <h2>Inventario</h2>
        <div class="grid-3">
          <label class="fld">SKU<input type="text" name="sku" value="<?= e($v('sku')) ?>" maxlength="80"></label>
          <label class="fld">Stock total<input type="number" name="stock" value="<?= (int)$v('stock', 0) ?>" min="0"></label>
          <div class="fld"><span>&nbsp;</span>
            <label class="switch"><input type="checkbox" name="track_stock" value="1" <?= !$p || (int)$p['track_stock'] ? 'checked' : '' ?>><span></span>Controlar inventario</label>
          </div>
        </div>
        <label class="switch"><input type="checkbox" name="allow_backorder" value="1" <?= (int)$v('allow_backorder', 0) ? 'checked' : '' ?>><span></span>Permitir pedidos sin stock (sobre pedido)</label>
      </section>

      <!-- Boosters -->
      <section class="card" id="boosters">
        <h2>Página de producto — elementos de venta</h2>
        <p class="muted">Activa lo que quieres que aparezca en la página de este producto.</p>
        <div class="boosters">
          <?php foreach ($boosters as $k => [$label, $hint]): ?>
            <label class="booster"><span><strong><?= e($label) ?></strong><small class="muted"><?= e($hint) ?></small></span><span class="switch"><input type="checkbox" name="boost_<?= $k ?>" value="1" <?= !empty($boost[$k]) ? 'checked' : '' ?> <?= $k === 'bundle' ? 'data-bundle-toggle' : '' ?>><span></span></span></label>
          <?php endforeach; ?>
        </div>
        <div class="bundle-pick" data-bundle-pick>
          <div class="card-head"><strong>Productos del paquete “Completa el look”</strong><label class="fld inline">Descuento del paquete<span class="money"><input type="number" name="bundle_pct" value="<?= (int)$boost['bundle_pct'] ?>" min="0" max="90"><i>%</i></span></label></div>
          <div class="pick-grid">
            <?php foreach ($all as $a): ?>
              <label class="pick"><input type="checkbox" name="bundle_ids[]" value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $bundleIds, true) ? 'checked' : '' ?>><?php if ($covers[$a['id']] ?? ''): ?><img src="<?= e(media_url($covers[$a['id']])) ?>" alt=""><?php endif; ?><span><?= e($a['name_es']) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <!-- SEO -->
      <section class="card" id="seo">
        <h2>SEO y vista en Google</h2>
        <div class="gpreview">
          <span class="g-url" data-g-url></span>
          <span class="g-title" data-g-title></span>
          <span class="g-desc" data-g-desc></span>
        </div>
        <label class="fld">URL del producto<span class="money"><i>/product/</i><input type="text" name="slug" value="<?= e($v('slug')) ?>" data-slug placeholder="se genera del nombre"></span></label>
        <div class="grid-2 tight">
          <label class="fld">Título SEO (inglés) <small class="muted" data-count-for="seo_title_en"></small><input type="text" name="seo_title_en" value="<?= e($seo['title_en']) ?>" maxlength="120" data-counter="60" placeholder="Si lo dejas vacío se usa el nombre"></label>
          <label class="fld">Título SEO (español) <small class="muted" data-count-for="seo_title_es"></small><input type="text" name="seo_title_es" value="<?= e($seo['title_es']) ?>" maxlength="120" data-counter="60"></label>
          <label class="fld">Descripción SEO (inglés) <small class="muted" data-count-for="seo_desc_en"></small><textarea name="seo_desc_en" rows="3" maxlength="300" data-counter="160"><?= e($seo['desc_en']) ?></textarea></label>
          <label class="fld">Descripción SEO (español) <small class="muted" data-count-for="seo_desc_es"></small><textarea name="seo_desc_es" rows="3" maxlength="300" data-counter="160"><?= e($seo['desc_es']) ?></textarea></label>
        </div>
      </section>
    </div>

    <aside class="pe-side">
      <section class="card">
        <h2>Vista previa en vivo</h2>
        <div class="live-card">
          <div class="lc-img"><img data-live-img alt="" hidden><span class="lc-badge" data-live-badge hidden></span><span class="lc-ph" data-live-ph>Sin foto</span></div>
          <span class="lc-cat" data-live-cat></span>
          <strong class="lc-name" data-live-title></strong>
          <span class="lc-price"><b data-live-price></b> <s data-live-compare></s></span>
        </div>
      </section>
      <section class="card">
        <h2>Publicación</h2>
        <label class="fld">Estado
          <select name="status">
            <?php foreach (['published' => 'Publicado', 'draft' => 'Borrador', 'hidden' => 'Oculto (solo con enlace)'] as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $v('status', 'draft') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="switch"><input type="checkbox" name="featured" value="1" <?= (int)$v('featured', 0) ? 'checked' : '' ?>><span></span>Destacar en Inicio</label>
      </section>
      <section class="card">
        <h2>Organización</h2>
        <div class="fld"><span>Categorías</span>
          <div class="cat-pills">
            <?php foreach ($cats as $c): ?>
              <label class="cat-pill"><input type="checkbox" name="cats[]" value="<?= e($c['slug']) ?>" data-cat-name="<?= e($c['name_en']) ?>" <?= in_array($c['slug'], $pcats, true) ? 'checked' : '' ?>><span><?= e($c['name_es']) ?></span></label>
            <?php endforeach; ?>
          </div>
          <a class="small" href="<?= e(url('/admin/categories')) ?>">Administrar categorías</a>
        </div>
        <label class="fld">Tipo<input type="text" name="type" list="types" value="<?= e($v('type')) ?>" data-type><datalist id="types"><?php foreach ($types as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist></label>
        <label class="fld">Material<input type="text" name="material" value="<?= e($v('material')) ?>" placeholder="Acero inoxidable 316L, baño de oro 18k"></label>
        <label class="fld">Etiquetas de búsqueda<input type="text" name="tags" value="<?= e($v('tags')) ?>" placeholder="perla, regalo, primera comunión"></label>
      </section>
      <?php if ($p): ?>
      <section class="card danger-zone">
        <button class="btn btn-ghost sm" type="submit" form="dup-form"><?= icon('copy', 16) ?>Duplicar</button>
        <button class="btn btn-danger sm" type="submit" form="del-form" data-confirm="¿Eliminar este producto? No se puede deshacer."><?= icon('trash', 16) ?>Eliminar</button>
      </section>
      <?php endif; ?>
    </aside>
  </div>
</form>
<?php if ($p): ?>
<form id="dup-form" method="post" action="<?= e(url('/admin/products/action?id=' . $p['id'])) ?>" hidden><?= csrf_field() ?><input type="hidden" name="do" value="duplicate"></form>
<form id="del-form" method="post" action="<?= e(url('/admin/products/action?id=' . $p['id'])) ?>" hidden><?= csrf_field() ?><input type="hidden" name="do" value="delete"></form>
<?php endif; ?>
<script type="application/json" id="product-data"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(url('/assets/js/product.js')) ?>?v=<?= APP_VERSION ?>" defer></script>
