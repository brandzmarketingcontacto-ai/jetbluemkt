<section class="wrap section-top section">
  <h1 class="h2">Tu carrito</h1>
  <?php if (!$lines): ?>
    <div class="card stack" style="margin-top:24px">
      <p class="lead">Tu carrito está vacío.</p>
      <a class="btn btn-red" href="<?= e(url('libros')) ?>">Ver libros</a>
    </div>
  <?php else: ?>
  <div class="split" style="margin-top:24px">
    <form class="split-a card stack" method="post" action="<?= e(url('carrito')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <?php foreach ($lines as $l): ?>
        <div class="line">
          <?php if ($l['image']): ?><img src="<?= e(asset($l['image'])) ?>" alt=""><?php endif; ?>
          <div class="grow">
            <b><?= e($l['title']) ?></b>
            <div class="small muted"><?= e($l['edition']) ?> · <?= money($l['price']) ?></div>
          </div>
          <label class="sr" for="q-<?= e($l['key']) ?>">Cantidad</label>
          <input class="input qty-in" id="q-<?= e($l['key']) ?>" type="number" min="0" max="50" name="qty[<?= e($l['key']) ?>]" value="<?= (int)$l['qty'] ?>">
          <b class="nowrap"><?= money($l['total']) ?></b>
        </div>
      <?php endforeach; ?>
      <button class="btn btn-ghost" type="submit">Actualizar cantidades</button>
    </form>
    <aside class="split-b card stack" aria-label="Resumen">
      <div class="sum"><span>Subtotal</span><span><?= money($totals['subtotal']) ?></span></div>
      <div class="sum"><span>Envío</span><span><?= $totals['shipping'] > 0 ? money($totals['shipping']) : 'Gratis' ?></span></div>
      <div class="sum sum-total"><span>Total</span><span><?= money($totals['total']) ?></span></div>
      <a class="btn btn-red btn-lg" href="<?= e(url('checkout')) ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        Comprar
      </a>
      <p class="small muted center-t">Pago seguro con tarjeta de crédito o débito</p>
    </aside>
  </div>
  <?php endif; ?>
</section>
