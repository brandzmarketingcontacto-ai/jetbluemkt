<?php /** @var array $o */ $items = jdec($o['items'], []); ?>
<div class="page-head">
  <div><a href="<?= e(url('/admin/orders')) ?>" class="small">← Pedidos</a><h1>Pedido <?= e($o['number']) ?></h1><p class="muted"><?= e(date('d/m/Y H:i', strtotime($o['created_at']))) ?> · <?= $o['lang'] === 'es' ? 'Español' : 'English' ?></p></div>
  <span class="st st-<?= e($o['status']) ?> lg"><?= e(ORDER_STATUSES[$o['status']] ?? $o['status']) ?></span>
</div>
<div class="grid-2 wide-left">
  <section class="card">
    <h2>Artículos</h2>
    <ul class="list">
      <?php foreach ($items as $it): ?>
        <li><span class="prod-cell"><?php if ($it['image']): ?><img src="<?= e(media_url($it['image'])) ?>" alt=""><?php endif; ?><span><strong><?= e($it['name']) ?></strong><small class="muted"><?= e($it['variant'] ?: '') ?> · SKU <?= e($it['sku']) ?></small></span></span><span><?= (int)$it['qty'] ?> × <?= e(money($it['price'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <dl class="totals">
      <div><dt>Subtotal</dt><dd><?= e(money($o['subtotal'])) ?></dd></div>
      <?php if ((float)$o['discount'] > 0): ?><div><dt>Descuento <?= e($o['coupon']) ?></dt><dd>−<?= e(money($o['discount'])) ?></dd></div><?php endif; ?>
      <div><dt>Envío</dt><dd><?= e(money($o['shipping'])) ?></dd></div>
      <div class="grand"><dt>Total</dt><dd><?= e(money($o['total'])) ?> <?= e($o['currency']) ?></dd></div>
    </dl>
    <?php if ($o['gift_message']): ?><div class="note-box"><strong>Mensaje de regalo:</strong> <?= e($o['gift_message']) ?></div><?php endif; ?>
  </section>
  <div class="stack">
    <section class="card">
      <h2>Cliente</h2>
      <p><strong><?= e($o['name'] ?: '—') ?></strong><br><?= e($o['email']) ?><br><?= e($o['phone']) ?></p>
      <?php if ($o['address']): ?><p class="muted pre"><?= e($o['address']) ?></p><?php endif; ?>
      <div class="row-gap">
        <?php if ($o['phone']): ?><a class="btn btn-wa sm" target="_blank" rel="noopener" href="https://wa.me/<?= e(preg_replace('/\D/', '', $o['phone'])) ?>"><?= icon('whatsapp', 16) ?>WhatsApp</a><?php endif; ?>
        <?php if ($o['email']): ?><a class="btn btn-ghost sm" href="mailto:<?= e($o['email']) ?>?subject=<?= rawurlencode('Pedido ' . $o['number']) ?>"><?= icon('mail', 16) ?>Correo</a><?php endif; ?>
      </div>
    </section>
    <form method="post" class="card">
      <?= csrf_field() ?>
      <h2>Estado del pedido</h2>
      <label class="fld">Estado<select name="status"><?php foreach (ORDER_STATUSES as $k => $lbl): ?><option value="<?= $k ?>" <?= $o['status'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></label>
      <label class="fld">Nota / número de rastreo<textarea name="note" rows="3"><?= e($o['note']) ?></textarea></label>
      <label class="check"><input type="checkbox" name="notify_customer" value="1" checked> Avisar al cliente por correo al marcar “Enviado”</label>
      <button class="btn btn-primary" type="submit">Guardar</button>
    </form>
  </div>
</div>
