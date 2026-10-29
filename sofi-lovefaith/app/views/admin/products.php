<?php /** @var array $list @var array $cats @var array $counts @var string $q @var string $cat @var string $status */
$stLabel = ['published' => 'Publicado', 'draft' => 'Borrador', 'hidden' => 'Oculto'];
$catNames = [];
foreach ($cats as $c) $catNames[$c['slug']] = $c['name_es'];
?>
<div class="page-head">
  <div><h1>Productos</h1><p class="muted">Joyería, perfumes y detalles. Arrastra las filas para cambiar el orden en la tienda.</p></div>
  <div class="row-gap">
    <a class="btn btn-ghost" href="<?= e(url('/admin/categories')) ?>"><?= icon('cats', 18) ?>Categorías</a>
    <a class="btn btn-primary" href="<?= e(url('/admin/products/edit')) ?>"><?= icon('plus', 18) ?>Nuevo producto</a>
  </div>
</div>

<div class="kpis">
  <div class="kpi"><span>Publicados</span><strong><?= $counts['active'] ?></strong></div>
  <div class="kpi"><span>En oferta</span><strong class="rose"><?= $counts['sale'] ?></strong></div>
  <div class="kpi"><span>Stock bajo (≤ <?= (int)setting('low_stock_at', '5') ?>)</span><strong class="warn"><?= $counts['low'] ?></strong></div>
  <div class="kpi"><span>Borradores</span><strong><?= $counts['draft'] ?></strong></div>
</div>

<section class="card flush">
  <form class="toolbar" method="get" action="<?= e(url('/admin/products')) ?>">
    <label class="search"><?= icon('search', 16) ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre o SKU…" aria-label="Buscar productos"></label>
    <select name="cat" aria-label="Categoría" data-autosubmit><option value="">Todas las categorías</option><?php foreach ($cats as $c): ?><option value="<?= e($c['slug']) ?>" <?= $cat === $c['slug'] ? 'selected' : '' ?>><?= e($c['name_es']) ?></option><?php endforeach; ?></select>
    <select name="status" aria-label="Estado" data-autosubmit><option value="">Todos los estados</option><?php foreach ($stLabel as $k => $v): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  </form>
  <form method="post" action="<?= e(url('/admin/products/action')) ?>" data-bulk>
    <?= csrf_field() ?>
    <div class="bulkbar" data-bulkbar hidden>
      <span data-bulk-n></span>
      <button class="btn btn-ghost sm" name="do" value="published">Publicar</button>
      <button class="btn btn-ghost sm" name="do" value="draft">Pasar a borrador</button>
      <button class="btn btn-ghost sm" name="do" value="duplicate">Duplicar</button>
      <button class="btn btn-danger sm" name="do" value="delete" data-confirm="¿Eliminar los productos seleccionados? No se puede deshacer.">Eliminar</button>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th class="w1"><input type="checkbox" data-check-all aria-label="Seleccionar todo"></th><th class="w1"></th><th>Producto</th><th>Categoría</th><th>Precio</th><th>Stock</th><th>Medios</th><th>Estado</th><th class="right">Acciones</th></tr></thead>
        <tbody data-sortable="<?= e(url('/admin/products/action')) ?>">
          <?php if (!$list): ?><tr><td colspan="9" class="empty-row">No hay productos con estos filtros. <a href="<?= e(url('/admin/products/edit')) ?>">Crea el primero</a>.</td></tr><?php endif; ?>
          <?php foreach ($list as $p): $cover = product_cover($p); $low = (int)$p['track_stock'] && (int)$p['stock'] <= (int)setting('low_stock_at', '5'); ?>
            <tr data-id="<?= (int)$p['id'] ?>" draggable="true">
              <td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" aria-label="Seleccionar <?= e($p['name_es']) ?>"></td>
              <td class="grip" title="Arrastra para ordenar"><?= icon('grip', 18) ?></td>
              <td><a class="prod-cell" href="<?= e(url('/admin/products/edit?id=' . $p['id'])) ?>">
                <?php if ($cover): ?><img src="<?= e(media_url($cover)) ?>" alt=""><?php else: ?><span class="ph">Foto</span><?php endif; ?>
                <span><strong><?= e($p['name_es']) ?></strong><small class="muted">SKU <?= e($p['sku'] ?: '—') ?><?= $p['featured'] ? ' · ★ Destacado' : '' ?></small></span></a></td>
              <td class="muted"><?= e(implode(', ', array_map(fn($s) => $catNames[$s] ?? $s, array_filter(explode(',', $p['categories']))))) ?></td>
              <td><strong><?= e(money($p['price'])) ?></strong><?php if (product_on_sale($p)): ?> <s class="muted"><?= e(money($p['compare_price'])) ?></s><?php endif; ?></td>
              <td class="<?= $low ? ((int)$p['stock'] === 0 ? 'bad' : 'warn') : '' ?>"><?= (int)$p['track_stock'] ? ((int)$p['stock'] === 0 ? 'Sin stock' : (int)$p['stock'] . ' uds') : '∞' ?></td>
              <td class="muted"><?= (int)$p['media_n'] ?></td>
              <td><span class="st st-<?= e($p['status']) ?>"><?= e($stLabel[$p['status']] ?? $p['status']) ?></span></td>
              <td class="right nowrap">
                <a class="icon-btn" href="<?= e(url('/admin/products/edit?id=' . $p['id'])) ?>" aria-label="Editar <?= e($p['name_es']) ?>"><?= icon('edit', 16) ?></a>
                <a class="icon-btn" href="<?= e(product_url($p)) ?>" target="_blank" rel="noopener" aria-label="Ver en tienda"><?= icon('external', 16) ?></a>
                <button class="icon-btn" name="do" value="duplicate" formaction="<?= e(url('/admin/products/action?id=' . $p['id'])) ?>" aria-label="Duplicar"><?= icon('copy', 16) ?></button>
                <button class="icon-btn danger" name="do" value="delete" formaction="<?= e(url('/admin/products/action?id=' . $p['id'])) ?>" data-confirm="¿Eliminar «<?= e($p['name_es']) ?>»?" aria-label="Eliminar"><?= icon('trash', 16) ?></button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
</section>
