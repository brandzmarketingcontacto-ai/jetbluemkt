<section class="wrap section-top section">
  <h1 class="h2">Finalizar compra</h1>
  <div class="split" style="margin-top:24px">
    <form class="split-a card form" method="post" action="<?= e(url('checkout')) ?>">
      <?= csrf_field() ?>
      <label for="o-name">Nombre completo</label>
      <input class="input" id="o-name" name="name" required autocomplete="name">
      <label for="o-email">Correo (para tu confirmación)</label>
      <input class="input" id="o-email" name="email" type="email" required autocomplete="email">
      <label for="o-phone">Teléfono</label>
      <input class="input" id="o-phone" name="phone" type="tel" autocomplete="tel">
      <label for="o-address">Dirección de envío</label>
      <textarea class="input" id="o-address" name="address" rows="3" required autocomplete="street-address" placeholder="Calle y número, ciudad, estado, código postal"></textarea>
      <?php if ($hasSigned): ?>
        <label for="o-ded">Dedicatoria (¿a nombre de quién?)</label>
        <textarea class="input" id="o-ded" name="dedication" rows="2" placeholder="Ej.: Para María, con cariño"></textarea>
      <?php endif; ?>
      <button class="btn btn-red btn-lg" type="submit">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        Comprar · <?= money($totals['total']) ?>
      </button>
      <p class="small muted center-t">Serás dirigido a la página de pago seguro para pagar con tarjeta.</p>
    </form>
    <aside class="split-b card stack" aria-label="Resumen del pedido">
      <?php foreach ($lines as $l): ?>
        <div class="sum"><span><?= e($l['title']) ?> · <?= e($l['edition']) ?> × <?= (int)$l['qty'] ?></span><span><?= money($l['total']) ?></span></div>
      <?php endforeach; ?>
      <div class="sum"><span>Envío</span><span><?= $totals['shipping'] > 0 ? money($totals['shipping']) : 'Gratis' ?></span></div>
      <div class="sum sum-total"><span>Total</span><span><?= money($totals['total']) ?></span></div>
    </aside>
  </div>
</section>
