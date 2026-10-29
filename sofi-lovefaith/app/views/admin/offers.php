<?php /** @var array $coupons @var array $cats */ ?>
<div class="page-head"><div><h1>Ofertas y cupones</h1><p class="muted">La barra en movimiento de arriba del sitio y los códigos de descuento.</p></div></div>

<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="bar">
  <div class="card-head"><h2>Barra de ofertas en movimiento</h2><label class="switch"><input type="checkbox" name="offer_bar_on" value="1" <?= setting_on('offer_bar_on', true) ? 'checked' : '' ?>><span></span>Mostrar</label></div>
  <div class="mq-preview" style="--mq-speed: <?= max(10, (int)setting('offer_bar_speed', '32')) ?>s" aria-hidden="true"><div class="mq-track" data-mq-preview><?php for ($i = 0; $i < 2; $i++) foreach (lines((string)setting('offer_bar_en')) as $m) echo '<span>♥ ' . e($m) . '</span>'; ?></div></div>
  <div class="grid-2 tight">
    <label class="fld">Mensajes en inglés (uno por línea)<textarea name="offer_bar_en" rows="6" data-mq-source><?= e(setting('offer_bar_en')) ?></textarea></label>
    <label class="fld">Mensajes en español (uno por línea)<textarea name="offer_bar_es" rows="6"><?= e(setting('offer_bar_es')) ?></textarea></label>
    <label class="fld">Velocidad (segundos por vuelta, más = más lento)<input type="number" name="offer_bar_speed" min="10" max="120" value="<?= (int)setting('offer_bar_speed', '32') ?>"></label>
    <label class="fld">Enlace al hacer clic<input type="text" name="offer_bar_link" value="<?= e(setting('offer_bar_link')) ?>" placeholder="/shop?sale=1"></label>
  </div>
  <button class="btn btn-primary" type="submit">Guardar barra</button>
</form>

<section class="card">
  <div class="card-head"><h2>Cupones de descuento</h2></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Código</th><th>Descuento</th><th>Mínimo</th><th>Solo categoría</th><th>Vence</th><th>Usos</th><th>Activo</th><th></th></tr></thead>
      <tbody>
        <?php foreach (array_merge($coupons, [null]) as $c): $fid = 'cp-' . ($c['id'] ?? 'new'); ?>
          <tr class="<?= $c ? '' : 'new-row' ?>">
            <td><input form="<?= $fid ?>" type="text" name="code" value="<?= e($c['code'] ?? '') ?>" placeholder="NUEVO10" class="mono" aria-label="Código" required></td>
            <td class="nowrap"><input form="<?= $fid ?>" type="number" step="0.01" min="0" name="value" value="<?= e($c['value'] ?? '') ?>" class="w-80" aria-label="Valor">
              <select form="<?= $fid ?>" name="type" aria-label="Tipo"><option value="percent" <?= ($c['type'] ?? '') === 'percent' ? 'selected' : '' ?>>%</option><option value="fixed" <?= ($c['type'] ?? '') === 'fixed' ? 'selected' : '' ?>>$</option></select></td>
            <td><input form="<?= $fid ?>" type="number" step="0.01" min="0" name="min_total" value="<?= e($c['min_total'] ?? '0') ?>" class="w-80" aria-label="Compra mínima"></td>
            <td><select form="<?= $fid ?>" name="category" aria-label="Categoría"><option value="">Toda la tienda</option><?php foreach ($cats as $ct): ?><option value="<?= e($ct['slug']) ?>" <?= ($c['category'] ?? '') === $ct['slug'] ? 'selected' : '' ?>><?= e($ct['name_es']) ?></option><?php endforeach; ?></select></td>
            <td><input form="<?= $fid ?>" type="datetime-local" name="expires_at" value="<?= e(!empty($c['expires_at']) ? date('Y-m-d\TH:i', strtotime($c['expires_at'])) : '') ?>" aria-label="Vence"></td>
            <td class="muted"><?= (int)($c['uses'] ?? 0) ?></td>
            <td><label class="switch"><input form="<?= $fid ?>" type="checkbox" name="active" value="1" <?= !$c || $c['active'] ? 'checked' : '' ?>><span></span><span class="sr-only">Activo</span></label></td>
            <td class="nowrap">
              <button form="<?= $fid ?>" class="btn btn-primary sm" type="submit"><?= $c ? 'Guardar' : 'Crear' ?></button>
              <?php if ($c): ?><button form="del-<?= $fid ?>" class="icon-btn danger" type="submit" data-confirm="¿Eliminar el cupón <?= e($c['code']) ?>?" aria-label="Eliminar"><?= icon('trash', 16) ?></button><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">Los clientes escriben el código en el carrito. El descuento se aplica también en el pago con Stripe.</p>
</section>
<?php foreach (array_merge($coupons, [null]) as $c): $fid = 'cp-' . ($c['id'] ?? 'new'); ?>
  <form id="<?= $fid ?>" method="post" hidden><?= csrf_field() ?><input type="hidden" name="do" value="coupon"><input type="hidden" name="id" value="<?= (int)($c['id'] ?? 0) ?>"></form>
  <?php if ($c): ?><form id="del-<?= $fid ?>" method="post" hidden><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form><?php endif; ?>
<?php endforeach; ?>
