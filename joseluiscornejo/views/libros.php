<section class="wrap section-top stack-sm">
  <span class="kicker">LIBROS</span>
  <h1 class="h2"><?= e(s('books_title')) ?></h1>
  <p class="lead"><?= e(s('books_text')) ?></p>
</section>

<?php foreach ($products as $p): ?>
  <?php if ($p['status'] === 'disponible'): $hasSigned = $p['price_signed'] !== null; ?>
  <section id="p<?= (int)$p['id'] ?>" class="wrap section product">
    <div class="product-img">
      <?php if ($p['image']): ?><img src="<?= e(asset($p['image'])) ?>" alt="Portada de <?= e($p['title']) ?>"><?php endif; ?>
    </div>
    <form class="product-info stack" method="post" action="<?= e(url('carrito')) ?>" data-product>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
      <span class="kicker">LIBRO · TAPA BLANDA</span>
      <h2 class="h2"><?= e($p['title']) ?></h2>
      <p class="lead"><?= e($p['description']) ?></p>

      <fieldset class="editions">
        <legend>Elige tu edición</legend>
        <?php if ($hasSigned): ?>
        <label class="edition">
          <input type="radio" name="edition" value="firmado" checked>
          <span class="ed-txt"><b>Ejemplar firmado y dedicado</b><small>Enviado por el autor</small></span>
          <span class="ed-price"><?= money($p['price_signed']) ?></span>
        </label>
        <?php endif; ?>
        <label class="edition">
          <input type="radio" name="edition" value="estandar" <?= $hasSigned ? '' : 'checked' ?>>
          <span class="ed-txt"><b>Ejemplar estándar</b><small>Tapa blanda</small></span>
          <span class="ed-price"><?= money($p['price']) ?></span>
        </label>
      </fieldset>

      <div class="row">
        <div class="qty">
          <button type="button" data-q="-1" aria-label="Quitar uno">−</button>
          <input type="number" name="qty" value="1" min="1" max="50" aria-label="Cantidad">
          <button type="button" data-q="1" aria-label="Añadir uno">+</button>
        </div>
        <button class="btn btn-red btn-lg grow" type="submit" name="buy" value="1">Comprar</button>
      </div>
      <button class="btn btn-ghost" type="submit">Añadir al carrito y seguir viendo</button>
      <p class="small muted center-t">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" style="vertical-align:-3px"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/></svg>
        Pago seguro con tarjeta de crédito o débito
      </p>
      <?php if ($p['amazon_url']): ?><a class="link" href="<?= e($p['amazon_url']) ?>" target="_blank" rel="noopener">¿Prefieres Amazon? Cómpralo ahí →</a><?php endif; ?>
    </form>
  </section>
  <?php endif; ?>
<?php endforeach; ?>

<?php $soon = array_filter($products, fn($p) => $p['status'] === 'proximamente'); if ($soon): ?>
<section class="wrap section"><div class="grid2"><?php foreach ($soon as $p) book_card($p); ?></div></section>
<?php endif; ?>
<script src="<?= e(url('assets/js/app.js')) ?>?v=1"></script>
