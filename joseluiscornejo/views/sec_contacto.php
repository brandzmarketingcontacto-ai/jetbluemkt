<section id="contacto" class="wrap section split">
  <div class="split-a stack">
    <span class="kicker">CONTACTO</span>
    <h2 class="h2"><?= e(s('contact_title')) ?></h2>
    <p class="lead"><?= e(s('contact_text')) ?></p>
    <a class="btn btn-wa btn-lg" href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l1.3-4A8 8 0 1 1 8 18.7z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8A4 4 0 0 1 10.2 11l.8-1-1-2z"/></svg>
      <?= e(s('whatsapp_label')) ?>
    </a>
    <div class="chips">
      <?php foreach (['facebook', 'instagram', 'youtube'] as $net): if (s($net . '_url')): ?>
        <a class="chip" href="<?= e(s($net . '_url')) ?>" target="_blank" rel="noopener"><?= e(s($net . '_label')) ?></a>
      <?php endif; endforeach; ?>
    </div>
  </div>
  <form id="formulario" class="split-b card form" method="post" action="<?= e(url('contacto')) ?>">
    <?= csrf_field() ?>
    <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
    <label for="c-nombre">Nombre</label>
    <input class="input" id="c-nombre" name="name" type="text" required autocomplete="name">
    <label for="c-correo">Correo</label>
    <input class="input" id="c-correo" name="email" type="email" required autocomplete="email">
    <label for="c-msg">Mensaje</label>
    <textarea class="input" id="c-msg" name="message" rows="4" required></textarea>
    <button class="btn btn-red btn-lg" type="submit">Enviar mensaje</button>
  </form>
</section>
