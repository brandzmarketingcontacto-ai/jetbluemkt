<?php /** @var ?array $order @var bool $paid */ ?>
<section class="blk pad-l">
  <div class="wrap narrow text-blk center">
    <img src="<?= e(media_url(brand_logo())) ?>" alt="" class="logo shape-circle" style="width:110px;height:110px">
    <p class="script lg">with love and faith</p>
    <h1><?= e(t('thanks_order')) ?></h1>
    <?php if ($order && $paid): ?>
      <p><?= e(t('thanks_order_text', ['n' => $order['number']])) ?></p>
      <div class="card order-box"><?= order_items_html($order) ?></div>
    <?php else: ?>
      <p><?= e(t('order_pending')) ?></p>
    <?php endif; ?>
    <div class="btn-row center">
      <a class="btn btn-primary" href="<?= e(url('/shop')) ?>"><?= e(t('keep_shopping')) ?></a>
      <a class="btn btn-wa" href="<?= e(wa_link()) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 18) ?>WhatsApp</a>
    </div>
  </div>
</section>
