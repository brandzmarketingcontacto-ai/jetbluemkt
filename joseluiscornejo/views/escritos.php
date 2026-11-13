<section class="wrap section-top stack-sm">
  <span class="kicker">ESCRITOS Y VIDEOS</span>
  <h1 class="h1 h1-sm"><?= v('posts_title') ?></h1>
  <p class="lead"><?= v('posts_text') ?></p>
  <div class="chips">
    <?php foreach (['' => 'Todo', 'escrito' => 'Escritos', 'video' => 'Videos', 'frase' => 'Frases diarias'] as $k => $label): ?>
      <a class="chip <?= $type === $k ? 'chip-on' : '' ?>" href="<?= e(url('escritos' . ($k ? '?tipo=' . $k : ''))) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</section>
<section class="wrap section">
  <?php if ($posts): ?>
    <div class="grid3"><?php foreach ($posts as $p) post_card($p); ?></div>
  <?php else: ?>
    <p class="muted">Pronto habrá contenido nuevo aquí.</p>
  <?php endif; ?>
</section>
