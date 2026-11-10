<section class="wrap-narrow section-top section center stack">
  <span class="kicker">PAGO RECIBIDO</span>
  <h1 class="h2">¡Gracias por tu compra!</h1>
  <?php if ($code): ?><p class="lead">Tu número de pedido es <b><?= e($code) ?></b>. Te enviamos la confirmación por correo.</p><?php endif; ?>
  <div class="row center-x">
    <a class="btn btn-light" href="<?= e(url('escritos')) ?>">Leer escritos</a>
    <a class="btn btn-wa" href="<?= e(whatsapp_link('Hola, tengo una pregunta sobre mi pedido ' . $code)) ?>" target="_blank" rel="noopener">Dudas por WhatsApp</a>
  </div>
</section>
