<section id="facilitacion" class="wrap section">
  <div class="head-block">
    <span class="kicker">FACILITACIÓN</span>
    <h2 class="h2"><?= v('fac_title') ?></h2>
    <div class="lead rich"><?= vr('fac_text') ?></div>
  </div>
  <div class="grid3">
    <?php
    $ficons = [
      '<circle cx="8" cy="8" r="3"/><circle cx="16" cy="16" r="3"/><path d="M10.5 10.5 13.5 13.5"/>',
      '<path d="M12 3v6M6 9h12M6 9v5M18 9v5M12 9v12"/>',
      '<path d="M4 14c4 0 6-4 8-8 2 4 4 8 8 8"/><path d="M4 19h16"/>',
    ];
    for ($i = 1; $i <= 3; $i++): ?>
      <div class="card feat">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#FF6B5E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $ficons[$i - 1] ?></svg>
        <h3><?= v("fac_c{$i}_title") ?></h3>
        <p class="muted"><?= v("fac_c{$i}_text") ?></p>
      </div>
    <?php endfor; ?>
  </div>
  <?php if (s('talk_show') === '1' || is_preview()): ?>
  <div class="cta-red"<?= ea('talk_show', 'show') ?><?= s('talk_show') !== '1' ? ' style="display:none"' : '' ?>>
    <div class="cta-text">
      <span class="kicker-sm light"><?= v('talk_kicker') ?></span>
      <h3><?= v('talk_title') ?></h3>
      <p><?= v('talk_text') ?></p>
    </div>
    <a class="btn btn-white btn-lg" href="<?= e(whatsapp_link('Hola, quiero reservar mi lugar en la plática: ' . s('talk_title'))) ?>" target="_blank" rel="noopener"><?= v('talk_button') ?></a>
  </div>
  <?php endif; ?>
</section>
