<?php /** @var array $stats @var array $recent @var array $low @var array $msgs */ ?>
<div class="page-head">
  <div><h1>¡Hola! <span class="script">Sofi</span></h1><p class="muted">Así va tu tienda hoy, <?= e(date('d/m/Y')) ?>.</p></div>
  <div class="row-gap">
    <a class="btn btn-ghost" href="<?= e(url('/admin/pages')) ?>"><?= icon('pages', 18) ?>Editar páginas</a>
    <a class="btn btn-primary" href="<?= e(url('/admin/products/edit')) ?>"><?= icon('plus', 18) ?>Nuevo producto</a>
  </div>
</div>

<div class="kpis">
  <div class="kpi"><span>Ventas del mes</span><strong><?= e(money($stats['sales_month'])) ?></strong></div>
  <div class="kpi"><span>Pedidos del mes</span><strong><?= (int)$stats['orders_month'] ?></strong></div>
  <div class="kpi"><span>Productos publicados</span><strong><?= (int)$stats['products'] ?></strong></div>
  <div class="kpi"><span>Mensajes nuevos</span><strong class="rose"><?= (int)$stats['messages'] ?></strong></div>
</div>

<?php
$setup = [
    ['Logotipo y favicon', (bool)setting('logo', ''), '/admin/settings#marca'],
    ['Pagos con Stripe', stripe_configured(), '/admin/settings#pagos'],
    ['Correo SMTP y notificaciones', setting('smtp_user', '') !== '' && setting('notify_emails', '') !== '', '/admin/settings#smtp'],
    ['Dominio del sitio (SEO)', setting('site_url', '') !== '', '/admin/seo'],
    ['Contraseña propia del panel', setting('admin_password_default', '0') !== '1', '/admin/settings#seguridad'],
];
$pending = array_filter($setup, fn($s) => !$s[1]);
if ($pending): ?>
<section class="card">
  <h2>Termina de configurar tu tienda</h2>
  <div class="setup">
    <?php foreach ($setup as [$label, $ok, $href]): ?>
      <a href="<?= e(url($href)) ?>" class="<?= $ok ? 'done' : '' ?>"><span class="dot"><?= $ok ? icon('check', 14) : '' ?></span><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Pedidos recientes</h2><a href="<?= e(url('/admin/orders')) ?>">Ver todos</a></div>
    <?php if (!$recent): ?><p class="muted">Aún no hay pedidos pagados. Cuando conectes Stripe aparecerán aquí.</p><?php endif; ?>
    <ul class="list">
      <?php foreach ($recent as $o): ?>
        <li><a href="<?= e(url('/admin/orders/view?id=' . $o['id'])) ?>"><strong><?= e($o['number']) ?></strong><span class="muted"><?= e($o['name'] ?: $o['email']) ?></span></a><span><?= e(money($o['total'])) ?></span><span class="st st-<?= e($o['status']) ?>"><?= e(ORDER_STATUSES[$o['status']] ?? $o['status']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="card">
    <div class="card-head"><h2>Mensajes</h2><a href="<?= e(url('/admin/forms')) ?>">Ver todos</a></div>
    <?php if (!$msgs): ?><p class="muted">No hay mensajes todavía.</p><?php endif; ?>
    <ul class="list">
      <?php foreach ($msgs as $m): ?>
        <li><a href="<?= e(url('/admin/forms?id=' . $m['id'])) ?>"><strong><?= e($m['name']) ?></strong><span class="muted ell"><?= e($m['subject'] . ' · ' . mb_substr((string)$m['message'], 0, 60)) ?></span></a><?php if ($m['status'] === 'new'): ?><span class="st st-new">Nuevo</span><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="card">
    <div class="card-head"><h2>Stock bajo</h2><a href="<?= e(url('/admin/products')) ?>">Productos</a></div>
    <?php if (!$low): ?><p class="muted">Todo el inventario está bien.</p><?php endif; ?>
    <ul class="list">
      <?php foreach ($low as $p): ?>
        <li><a href="<?= e(url('/admin/products/edit?id=' . $p['id'])) ?>"><strong><?= e($p['name_es']) ?></strong></a><span class="<?= (int)$p['stock'] === 0 ? 'bad' : 'warn' ?>"><?= (int)$p['stock'] === 0 ? 'Sin stock' : (int)$p['stock'] . ' uds' ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="card">
    <div class="card-head"><h2>Accesos rápidos</h2></div>
    <div class="quick">
      <a href="<?= e(url('/admin/offers')) ?>"><?= icon('tag', 20) ?>Barra de ofertas y cupones</a>
      <a href="<?= e(url('/admin/settings#redes')) ?>"><?= icon('instagram', 20) ?>Redes sociales y WhatsApp</a>
      <a href="<?= e(url('/admin/seo')) ?>"><?= icon('seo', 20) ?>SEO y vista al compartir</a>
      <a href="<?= e(url('/admin/backup')) ?>"><?= icon('database', 20) ?>Descargar respaldo</a>
    </div>
  </section>
</div>
