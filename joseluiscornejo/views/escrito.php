<article class="wrap-narrow section-top section stack">
  <a class="link" href="<?= e(url('escritos')) ?>">← Escritos y videos</a>
  <h1 class="h1 h1-sm"><?= e($post['title']) ?></h1>
  <?php $yt = youtube_id((string)$post['youtube_url']); if ($yt): ?>
    <div class="video"><iframe src="https://www.youtube-nocookie.com/embed/<?= e($yt) ?>" title="<?= e($post['title']) ?>" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>
  <?php endif; ?>
  <div class="rich prose"><?= $post['body'] ?></div>
  <div class="cta-red">
    <div class="cta-text"><h3>¿Te resonó este texto?</h3><p>Encuentra más en «Ecos del pasado: voces del silencio».</p></div>
    <a class="btn btn-white btn-lg" href="<?= e(url('libros')) ?>">Ver el libro</a>
  </div>
</article>
