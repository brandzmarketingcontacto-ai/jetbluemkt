<?php /** @var array $list @var string $status */ ?>
<div class="page-head"><div><h1>Pedidos</h1><p class="muted">Los pedidos pagados con Stripe aparecen aquí automáticamente.</p></div></div>
<div class="seg wrap-tabs">
  <a href="<?= e(url('/admin/orders')) ?>" class="<?= $status === '' ? 'on' : '' ?>">Todos</a>
  <?php foreach (ORDER_STATUSES as $k => $lbl): ?><a href="<?= e(url('/admin/orders?status=' . $k)) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= e($lbl) ?></a><?php endforeach; ?>
</div>
<section class="card flush">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Pedido</th><th>Fecha</th><th>Cliente</th><th>Artículos</th><th>Total</th><th>Estado</th></tr></thead>
      <tbody>
        <?php if (!$list): ?><tr><td colspan="6" class="empty-row">No hay pedidos todavía.</td></tr><?php endif; ?>
        <?php foreach ($list as $o): $items = jdec($o['items'], []); ?>
          <tr>
            <td><a href="<?= e(url('/admin/orders/view?id=' . $o['id'])) ?>"><strong><?= e($o['number']) ?></strong></a></td>
            <td class="muted"><?= e(date('d/m/Y H:i', strtotime($o['created_at']))) ?></td>
            <td><?= e($o['name'] ?: '—') ?><br><small class="muted"><?= e($o['email']) ?></small></td>
            <td class="muted"><?= array_sum(array_column($items, 'qty')) ?></td>
            <td><strong><?= e(money($o['total'])) ?></strong></td>
            <td><span class="st st-<?= e($o['status']) ?>"><?= e(ORDER_STATUSES[$o['status']] ?? $o['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
