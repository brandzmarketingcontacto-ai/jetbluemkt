<?php /** @var array $items */ ?>
<div class="page-head">
  <div><h1>Biblioteca de medios</h1><p class="muted">Todas las fotos, GIFs y videos subidos. Límite por archivo del hosting: <?= e(ini_get('upload_max_filesize')) ?>.</p></div>
  <label class="btn btn-primary"><?= icon('upload', 18) ?>Subir archivos<input type="file" multiple hidden data-library-upload accept="image/*,video/mp4,video/webm,video/quicktime"></label>
</div>
<div class="upload-progress" data-upload-progress hidden><div></div><span></span></div>
<section class="lib-grid">
  <?php if (!$items): ?><p class="muted">Aún no hay archivos.</p><?php endif; ?>
  <?php foreach ($items as $m): ?>
    <figure class="lib-item">
      <?php if ($m['kind'] === 'video'): ?><video src="<?= e(media_url($m['path'])) ?>" muted preload="metadata"></video><span class="kind">VIDEO</span>
      <?php else: ?><img src="<?= e(media_url($m['path'])) ?>" alt="" loading="lazy"><?php if ($m['kind'] === 'gif'): ?><span class="kind">GIF</span><?php endif; ?><?php endif; ?>
      <figcaption>
        <span class="ell" title="<?= e($m['original']) ?>"><?= e($m['original']) ?></span>
        <small class="muted"><?= $m['width'] ? (int)$m['width'] . '×' . (int)$m['height'] . ' · ' : '' ?><?= e(number_format($m['size'] / 1024, 0)) ?> KB</small>
        <span class="row-gap">
          <button type="button" class="icon-btn" data-copy="<?= e(abs_url($m['path'])) ?>" aria-label="Copiar enlace"><?= icon('copy', 16) ?></button>
          <form method="post" class="inline"><?= csrf_field() ?><button class="icon-btn danger" name="delete" value="<?= (int)$m['id'] ?>" data-confirm="¿Eliminar este archivo? Si un producto lo usa, la foto se conservará en ese producto." aria-label="Eliminar"><?= icon('trash', 16) ?></button></form>
        </span>
      </figcaption>
    </figure>
  <?php endforeach; ?>
</section>
