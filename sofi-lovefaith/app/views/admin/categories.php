<?php /** @var array $cats */ ?>
<div class="page-head"><div><h1>Categorías</h1><p class="muted">Aparecen en Inicio, en los filtros de la Tienda y en el pie de página.</p></div><a class="btn btn-ghost" href="<?= e(url('/admin/products')) ?>">← Productos</a></div>
<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Imagen</th><th>Nombre (inglés)</th><th>Nombre (español)</th><th>Orden</th><th>Productos</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td><div class="img-field sm" data-image-field><input type="hidden" name="cat[<?= (int)$c['id'] ?>][image]" value="<?= e($c['image']) ?>"><button type="button" class="thumb-btn" data-pick="image" aria-label="Cambiar imagen"><?php if ($c['image']): ?><img src="<?= e(media_url($c['image'])) ?>" alt=""><?php else: ?><?= icon('image', 18) ?><?php endif; ?></button></div></td>
            <td><input type="text" name="cat[<?= (int)$c['id'] ?>][name_en]" value="<?= e($c['name_en']) ?>" aria-label="Nombre en inglés"></td>
            <td><input type="text" name="cat[<?= (int)$c['id'] ?>][name_es]" value="<?= e($c['name_es']) ?>" aria-label="Nombre en español"></td>
            <td><input type="number" name="cat[<?= (int)$c['id'] ?>][sort]" value="<?= (int)$c['sort'] ?>" class="w-80" aria-label="Orden"></td>
            <td class="muted"><?= (int)$c['n'] ?> · <code>/shop?cat=<?= e($c['slug']) ?></code></td>
            <td><button class="icon-btn danger" type="submit" form="del-<?= (int)$c['id'] ?>" data-confirm="¿Eliminar la categoría «<?= e($c['name_es']) ?>»?" aria-label="Eliminar"><?= icon('trash', 16) ?></button></td>
          </tr>
        <?php endforeach; ?>
        <tr class="new-row">
          <td><?= icon('plus', 18) ?></td>
          <td><input type="text" name="new_en" placeholder="Nueva categoría (inglés)" aria-label="Nueva categoría en inglés"></td>
          <td><input type="text" name="new_es" placeholder="Nombre en español" aria-label="Nueva categoría en español"></td>
          <td colspan="3"></td>
        </tr>
      </tbody>
    </table>
  </div>
  <button class="btn btn-primary" type="submit">Guardar categorías</button>
</form>
<?php foreach ($cats as $c): ?>
<form id="del-<?= (int)$c['id'] ?>" method="post" hidden><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
<?php endforeach; ?>
