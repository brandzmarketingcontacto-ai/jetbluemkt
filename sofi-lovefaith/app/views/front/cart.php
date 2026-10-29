<?php
/** @var array $sum */
$l = lang();
$waText = ($l === 'es' ? "Hola! Quiero hacer este pedido:\n" : "Hi! I'd like to order:\n");
foreach ($sum['lines'] as $line) $waText .= '• ' . $line['qty'] . ' × ' . $line['name'] . ($line['variant_name'] ? ' (' . $line['variant_name'] . ')' : '') . ' — ' . money($line['total']) . "\n";
$waText .= 'Total: ' . money($sum['total']) . ($sum['coupon'] ? ' (' . $sum['coupon']['code'] . ')' : '');
?>
<section class="blk pad-m">
  <div class="wrap">
    <h1 class="page-title"><?= e(t('your_cart')) ?></h1>
    <?php if (!$sum['lines']): ?>
      <div class="empty-cart">
        <?= icon('bag', 48) ?>
        <p><?= e(t('cart_empty')) ?></p>
        <a class="btn btn-primary" href="<?= e(url('/shop')) ?>"><?= e(t('keep_shopping')) ?></a>
      </div>
    <?php else: ?>
    <div class="cart-layout">
      <form method="post" action="<?= e(url('/cart/update')) ?>" class="cart-lines">
        <?= csrf_field() ?>
        <?php foreach ($sum['lines'] as $line): ?>
          <div class="cart-line">
            <a href="<?= e(product_url($line['product'])) ?>" class="cl-img"><?php if ($line['image']): ?><img src="<?= e(media_url($line['image'])) ?>" alt=""><?php endif; ?></a>
            <div class="cl-info">
              <a href="<?= e(product_url($line['product'])) ?>" class="cl-name"><?= e($line['name']) ?></a>
              <?php if ($line['variant_name']): ?><span class="muted"><?= e($line['variant_name']) ?></span><?php endif; ?>
              <span class="cl-price"><?= e(money($line['price'])) ?></span>
            </div>
            <label class="cl-qty"><span class="sr-only"><?= e(t('quantity')) ?></span><input type="number" min="0" max="99" name="qty[<?= e($line['key']) ?>]" value="<?= (int)$line['qty'] ?>" data-autosubmit></label>
            <strong class="cl-total"><?= e(money($line['total'])) ?></strong>
            <button class="icon-btn" type="submit" name="remove" value="<?= e($line['key']) ?>" aria-label="<?= e(t('remove')) ?>"><?= icon('trash', 18) ?></button>
          </div>
        <?php endforeach; ?>
        <label class="gift-note"><?= e(t('gift_message')) ?>
          <textarea name="gift_message" rows="2" maxlength="500"><?= e($_SESSION['gift_message'] ?? '') ?></textarea>
        </label>
        <div class="cart-actions">
          <a href="<?= e(url('/shop')) ?>">← <?= e(t('keep_shopping')) ?></a>
          <button class="btn btn-outline" type="submit"><?= e(t('update')) ?></button>
        </div>
      </form>

      <aside class="card cart-sum">
        <form method="post" action="<?= e(url('/cart/coupon')) ?>" class="coupon">
          <?= csrf_field() ?>
          <?php if ($sum['coupon']): ?>
            <span class="pill"><?= icon('tag', 14) ?><?= e($sum['coupon']['code']) ?></span>
            <button class="link" type="submit" name="remove_coupon" value="1"><?= e(t('remove')) ?></button>
          <?php else: ?>
            <label class="sr-only" for="code"><?= e(t('coupon')) ?></label>
            <input id="code" name="code" placeholder="<?= e(t('coupon')) ?>" autocomplete="off">
            <button class="btn btn-outline" type="submit"><?= e(t('apply')) ?></button>
          <?php endif; ?>
        </form>
        <dl>
          <div><dt><?= e(t('subtotal')) ?></dt><dd><?= e(money($sum['subtotal'])) ?></dd></div>
          <?php if ($sum['discount'] > 0): ?><div class="disc"><dt><?= e(t('discount')) ?></dt><dd>−<?= e(money($sum['discount'])) ?></dd></div><?php endif; ?>
          <div><dt><?= e(t('shipping')) ?></dt><dd><?= $sum['shipping'] > 0 ? e(money($sum['shipping'])) : e(t('free')) ?></dd></div>
          <div class="grand"><dt><?= e(t('total')) ?></dt><dd><?= e(money($sum['total'])) ?></dd></div>
        </dl>
        <?php if ($sum['free_from'] > 0 && $sum['missing_free'] > 0): ?>
          <div class="ship-progress"><span><?= t('free_ship_away', ['amt' => '<strong>' . e(money($sum['missing_free'])) . '</strong>']) ?></span><div class="bar"><div style="width: <?= max(4, (int)round(100 - $sum['missing_free'] / $sum['free_from'] * 100)) ?>%"></div></div></div>
        <?php endif; ?>
        <?php if (stripe_configured()): ?>
          <form method="post" action="<?= e(url('/checkout')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-primary block lg" type="submit"><?= icon('lock', 18) ?><?= e(t('checkout')) ?></button>
          </form>
          <p class="secure-note center"><?= e(t('secure_stripe')) ?></p>
        <?php else: ?>
          <p class="notice"><?= e(t('payments_off')) ?></p>
        <?php endif; ?>
        <a class="btn btn-wa block" href="<?= e(wa_link($waText)) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 18) ?><?= e(t('checkout_wa')) ?></a>
      </aside>
    </div>
    <?php endif; ?>
  </div>
</section>
