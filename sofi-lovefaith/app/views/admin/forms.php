<?php /** @var string $tab @var array $list @var ?array $current @var array $counts @var string $filter */
$tabs = ['contact' => ['Contacto', $counts['contact']], 'newsletter' => ['Suscripciones', $counts['newsletter']], 'reviews' => ['Reseñas por aprobar', $counts['reviews']]];
?>
<div class="page-head">
  <div><h1>Formularios</h1><p class="muted">Todo lo que te escriben en el sitio queda guardado aquí y también llega a tu correo de notificaciones.</p></div>
  <?php if ($tab !== 'reviews'): ?><a class="btn btn-ghost" href="<?= e(url('/admin/forms/export?form=' . $tab)) ?>"><?= icon('down', 16) ?>Exportar CSV</a><?php endif; ?>
</div>
<div class="seg wrap-tabs">
  <?php foreach ($tabs as $k => [$lbl, $n]): ?><a href="<?= e(url('/admin/forms?tab=' . $k)) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($lbl) ?><?php if ($n): ?> <em><?= (int)$n ?></em><?php endif; ?></a><?php endforeach; ?>
</div>

<?php if ($tab === 'reviews'): ?>
  <section class="card flush">
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Producto</th><th>Cliente</th><th>Reseña</th><th>Estado</th><th class="right">Acciones</th></tr></thead>
      <tbody>
      <?php if (!$list): ?><tr><td colspan="5" class="empty-row">Aún no hay reseñas.</td></tr><?php endif; ?>
      <?php foreach ($list as $r): ?>
        <tr>
          <td><a href="<?= e(url('/product/' . $r['slug'])) ?>" target="_blank" rel="noopener"><?= e($r['product']) ?></a></td>
          <td><?= e($r['name']) ?><br><small class="muted"><?= e($r['email']) ?></small></td>
          <td><span class="stars"><?= str_repeat('★', (int)$r['rating']) ?></span> <strong><?= e($r['title']) ?></strong><br><span class="muted"><?= e($r['body']) ?></span></td>
          <td><span class="st st-<?= $r['status'] === 'approved' ? 'published' : 'new' ?>"><?= $r['status'] === 'approved' ? 'Publicada' : 'Pendiente' ?></span></td>
          <td class="right nowrap">
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <?php if ($r['status'] !== 'approved'): ?><button class="btn btn-primary sm" name="do" value="approve">Aprobar</button><?php else: ?><button class="btn btn-ghost sm" name="do" value="hide">Ocultar</button><?php endif; ?>
              <button class="icon-btn danger" name="do" value="delete" data-confirm="¿Eliminar esta reseña?" aria-label="Eliminar"><?= icon('trash', 16) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
<?php elseif ($tab === 'newsletter'): ?>
  <section class="card flush">
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Correo</th><th>Idioma</th><th>Fecha</th><th></th></tr></thead>
      <tbody>
      <?php if (!$list): ?><tr><td colspan="4" class="empty-row">Aún no hay suscripciones. Agrega el bloque “Suscripción” en el Editor de páginas.</td></tr><?php endif; ?>
      <?php foreach ($list as $s): ?>
        <tr><td><?= e($s['email']) ?></td><td><?= e(strtoupper($s['lang'])) ?></td><td class="muted"><?= e(date('d/m/Y', strtotime($s['created_at']))) ?></td>
        <td class="right"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="icon-btn danger" name="do" value="delete" data-confirm="¿Eliminar?" aria-label="Eliminar"><?= icon('trash', 16) ?></button></form></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
<?php else: ?>
  <div class="inbox card flush">
    <div class="inbox-list">
      <div class="seg small pad">
        <a href="<?= e(url('/admin/forms?f=all')) ?>" class="<?= $filter === 'all' ? 'on' : '' ?>">Bandeja</a>
        <a href="<?= e(url('/admin/forms?f=new')) ?>" class="<?= $filter === 'new' ? 'on' : '' ?>">Nuevos</a>
        <a href="<?= e(url('/admin/forms?f=archived')) ?>" class="<?= $filter === 'archived' ? 'on' : '' ?>">Archivados</a>
      </div>
      <?php if (!$list): ?><p class="muted pad">No hay mensajes aquí.</p><?php endif; ?>
      <?php foreach ($list as $m): $on = $current && $current['id'] === $m['id']; ?>
        <a href="<?= e(url('/admin/forms?f=' . $filter . '&id=' . $m['id'])) ?>" class="msg <?= $on ? 'on' : '' ?>">
          <span class="avatar"><?= e(mb_strtoupper(mb_substr($m['name'], 0, 1) . mb_substr((string)strstr($m['name'], ' '), 1, 1))) ?></span>
          <span class="msg-body"><span class="msg-top"><strong><?= e($m['name']) ?></strong><small class="muted"><?= e(date('d/m H:i', strtotime($m['created_at']))) ?></small></span>
          <span class="small"><?= e($m['subject']) ?></span><span class="muted ell"><?= e(mb_substr((string)$m['message'], 0, 90)) ?></span></span>
          <?php if ($m['status'] === 'new'): ?><span class="unread" aria-label="Sin atender"></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <article class="inbox-detail">
      <?php if ($current): ?>
        <div class="card-head">
          <div class="row-gap center-v"><span class="avatar lg"><?= e(mb_strtoupper(mb_substr($current['name'], 0, 1))) ?></span><div><h2><?= e($current['name']) ?></h2><span class="muted">Recibido <?= e(date('d/m/Y H:i', strtotime($current['created_at']))) ?> · <?= e(strtoupper($current['lang'])) ?></span></div></div>
          <form method="post" class="row-gap"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
            <?php if ($current['status'] !== 'archived'): ?><button class="btn btn-ghost sm" name="do" value="archived">Archivar</button><?php else: ?><button class="btn btn-ghost sm" name="do" value="new">Mover a bandeja</button><?php endif; ?>
            <button class="icon-btn danger bordered" name="do" value="delete" data-confirm="¿Eliminar este mensaje?" aria-label="Eliminar"><?= icon('trash', 16) ?></button>
          </form>
        </div>
        <dl class="facts">
          <div><dt>Correo</dt><dd><?= e($current['email']) ?></dd></div>
          <div><dt>Teléfono / WhatsApp</dt><dd><?= e($current['phone'] ?: '—') ?></dd></div>
          <div><dt>Asunto</dt><dd><?= e($current['subject']) ?></dd></div>
          <div><dt>Ofertas por correo</dt><dd><?= $current['newsletter'] ? 'Sí, aceptó' : 'No' ?></dd></div>
        </dl>
        <div class="msg-text"><?= nl2br(e($current['message'])) ?></div>
        <div class="row-gap">
          <?php if ($current['phone']): $ph = preg_replace('/\D/', '', $current['phone']); if (strlen($ph) === 10) $ph = '1' . $ph; ?><a class="btn btn-wa" target="_blank" rel="noopener" href="https://wa.me/<?= e($ph) ?>"><?= icon('whatsapp', 16) ?>Responder por WhatsApp</a><?php endif; ?>
          <a class="btn btn-primary" href="mailto:<?= e($current['email']) ?>?subject=<?= rawurlencode('Re: ' . $current['subject']) ?>"><?= icon('mail', 16) ?>Responder por correo</a>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$current['id'] ?>"><button class="btn btn-ghost" name="do" value="<?= $current['status'] === 'new' ? 'done' : 'new' ?>"><?= $current['status'] === 'new' ? 'Marcar como atendido' : 'Marcar como nuevo' ?></button></form>
        </div>
        <form method="post" class="fld"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$current['id'] ?>"><input type="hidden" name="do" value="note">
          <span>Nota interna</span><textarea name="note" rows="3" placeholder="Solo visible en el panel…"><?= e($current['note']) ?></textarea>
          <button class="btn btn-ghost sm" type="submit">Guardar nota</button>
        </form>
      <?php else: ?>
        <p class="muted">Selecciona un mensaje.</p>
      <?php endif; ?>
    </article>
  </div>
<?php endif; ?>
