<?php
// Piezas reutilizables de las vistas públicas.

function book_card(array $p): void { ?>
  <article class="card book">
    <?php if ($p['image']): ?>
      <img src="<?= e(asset($p['image'])) ?>" alt="Portada de <?= e($p['title']) ?>" class="book-cover" loading="lazy">
    <?php else: ?>
      <div class="book-cover book-ph"><span><?= e(mb_strtoupper($p['title'])) ?></span></div>
    <?php endif; ?>
    <div class="book-body">
      <?php if ($p['status'] === 'proximamente'): ?>
        <span class="pill pill-soon">Próximamente</span>
      <?php else: ?>
        <span class="pill pill-ok">Disponible</span>
      <?php endif; ?>
      <h3><?= e($p['title']) ?></h3>
      <p class="muted"><?= e($p['description']) ?></p>
      <?php if ($p['status'] === 'disponible'): ?>
        <p class="price">desde <?= money($p['price']) ?></p>
        <div class="row">
          <a class="btn btn-red" href="<?= e(url('libros#p' . $p['id'])) ?>">Comprar</a>
          <?php if ($p['amazon_url']): ?><a class="link" href="<?= e($p['amazon_url']) ?>" target="_blank" rel="noopener">Amazon</a><?php endif; ?>
        </div>
      <?php else: ?>
        <form class="row" method="post" action="<?= e(url('aviso')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="source" value="<?= e($p['slug']) ?>">
          <label class="sr" for="aviso-<?= (int)$p['id'] ?>">Correo para aviso</label>
          <input id="aviso-<?= (int)$p['id'] ?>" class="input grow" type="email" name="email" required placeholder="Tu correo para avisarte">
          <button class="btn btn-light" type="submit">Avísame</button>
        </form>
      <?php endif; ?>
    </div>
  </article>
<?php }

function post_card(array $p): void {
    $yt = youtube_id((string)$p['youtube_url']);
    if ($p['type'] === 'video'): ?>
      <article class="post-video">
        <?php if ($yt): ?>
          <div class="video"><iframe src="https://www.youtube-nocookie.com/embed/<?= e($yt) ?>" title="<?= e($p['title']) ?>" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>
        <?php else: ?>
          <a class="video video-ph" href="<?= e(s('youtube_url') ?: '#') ?>" target="_blank" rel="noopener" aria-label="Ver en YouTube"><span class="play"><svg width="22" height="22" viewBox="0 0 24 24" fill="#fff" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span></a>
        <?php endif; ?>
        <span class="kicker-sm">VIDEO · YOUTUBE</span>
        <h3><?= e($p['title']) ?></h3>
      </article>
    <?php elseif ($p['type'] === 'frase'): ?>
      <article class="card card-red">
        <span class="kicker-sm light">FRASE DEL DÍA · DEL LIBRO</span>
        <p class="quote-sm"><?= e($p['excerpt'] ?: strip_tags((string)$p['body'])) ?></p>
        <a class="btn btn-white" href="<?= e(url('libros')) ?>">Leer el libro completo</a>
      </article>
    <?php else: ?>
      <a class="card post-text" href="<?= e(url('escrito/' . $p['slug'])) ?>">
        <span class="kicker-sm red">ESCRITO</span>
        <h3><?= e($p['title']) ?></h3>
        <p class="serif muted"><?= e($p['excerpt'] ?: mb_strimwidth(strip_tags((string)$p['body']), 0, 220, '…')) ?></p>
        <span class="link red">Seguir leyendo →</span>
      </a>
    <?php endif;
}
