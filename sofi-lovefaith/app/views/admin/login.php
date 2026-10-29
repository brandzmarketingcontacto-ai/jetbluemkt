<?php /** @var string $error @var bool $locked */ ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Acceso — Panel Sofi</title>
<link rel="icon" href="<?= e(media_url(brand_favicon())) ?>">
<link href="https://fonts.googleapis.com/css2?family=Great+Vibes&family=Jost:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('/assets/css/admin.css')) ?>?v=<?= APP_VERSION ?>">
</head>
<body class="login-body">
<form class="login-card" method="post" action="<?= e(url('/admin/login')) ?>">
  <?= csrf_field() ?>
  <div class="login-head">
    <img src="<?= e(media_url(brand_logo())) ?>" alt="<?= e(setting('store_name', 'Sofi Love & Faith')) ?>">
    <span class="script"><?= e(setting('store_name', 'Sofi Love & Faith')) ?></span>
    <h1>Panel de administración</h1>
    <p class="muted">Ingresa tu contraseña para continuar</p>
  </div>
  <?php if ($error): ?><p class="flash error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <label class="fld">Contraseña
    <span class="pw">
      <?= icon('lock', 18) ?>
      <input type="password" name="password" autocomplete="current-password" required autofocus <?= $locked ? 'disabled' : '' ?>>
      <button type="button" class="icon-btn" data-pw-toggle aria-label="Mostrar contraseña"><?= icon('eye', 18) ?></button>
    </span>
  </label>
  <label class="check"><input type="checkbox" name="remember" value="1" checked> Mantener sesión iniciada en este equipo</label>
  <button class="btn btn-primary block lg" type="submit" <?= $locked ? 'disabled' : '' ?>>Entrar al panel</button>
  <a class="center-link" href="<?= e(url('/')) ?>">← Volver a la tienda</a>
</form>
<script src="<?= e(url('/assets/js/admin.js')) ?>?v=<?= APP_VERSION ?>"></script>
</body>
</html>
