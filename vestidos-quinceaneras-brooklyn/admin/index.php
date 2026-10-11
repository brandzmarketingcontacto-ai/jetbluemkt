<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/bootstrap.php';
require_installed('../install.php');
require_admin();
header('Cache-Control: no-store');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title>Panel — <?= e(setting('site_title', 'Vestidos para Quinceañeras Brooklyn')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,600;1,6..96,500&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css?v=1">
</head>
<body class="app">
<aside class="side">
  <div class="side-brand">
    <img src="../<?= e(safe_url(setting('logo_white', 'assets/img/logo-white.png'))) ?>" alt="<?= e(setting('site_title')) ?>" id="side-logo">
    <span>Panel de contenido</span>
  </div>
  <nav class="side-nav" aria-label="Secciones del panel">
    <button type="button" data-view="pagina" class="is-on" aria-current="page"><span></span>Página</button>
    <button type="button" data-view="vestidos"><span></span>Vestidos</button>
    <button type="button" data-view="accesorios"><span></span>Accesorios</button>
    <button type="button" data-view="faqs"><span></span>Preguntas</button>
    <button type="button" data-view="media"><span></span>Imágenes</button>
    <button type="button" data-view="ajustes"><span></span>Ajustes</button>
  </nav>
  <div class="side-foot">
    <a href="../" target="_blank" rel="noopener">Ver sitio ↗</a>
    <a href="logout.php">Cerrar sesión</a>
  </div>
</aside>
<main class="main">
  <header class="topbar">
    <div><h1 id="view-title">Página</h1><span id="view-sub">Ordena secciones, edita textos con formato y cambia imágenes</span></div>
    <div class="topbar-actions"><span class="status" id="status" data-state="ok">Todo guardado</span></div>
  </header>
  <div class="content" id="view"><p class="loading">Cargando…</p></div>
</main>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<dialog class="picker" id="picker" aria-labelledby="picker-title">
  <div class="picker-head">
    <h2 id="picker-title">Elegir imagen</h2>
    <div class="row">
      <label class="btn btn-sm">Subir fotos<input type="file" accept="image/*" multiple class="sr" id="picker-file"></label>
      <button type="button" class="btn btn-sm btn-light" id="picker-close">Cerrar</button>
    </div>
  </div>
  <div class="dropzone" id="picker-drop">Arrastra fotos aquí para subirlas</div>
  <div class="media-grid" id="picker-grid"></div>
</dialog>
<script type="module" src="app.js?v=1"></script>
</body>
</html>
