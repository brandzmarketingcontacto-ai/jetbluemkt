<?php
// Instalador: conecta con MySQL, crea las tablas, carga el contenido inicial y guarda inc/config.php.

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/schema.php';

$installed = app_config() !== null;
$error = '';
$done = false;
$form = [
    'db_host' => 'localhost',
    'db_name' => 'u731277802_vqbdb',
    'db_user' => 'u731277802_vqb',
    'db_pass' => '',
];

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    try {
        $pdo = db_connect($form['db_host'], $form['db_name'], $form['db_user'], $form['db_pass']);
        foreach (schema_statements() as $sql) {
            $pdo->exec($sql);
        }
        seed_database($pdo);

        $php = "<?php\n// Generado por install.php. No subir este archivo a GitHub.\nreturn " . var_export([
            'db_host' => $form['db_host'],
            'db_name' => $form['db_name'],
            'db_user' => $form['db_user'],
            'db_pass' => $form['db_pass'],
        ], true) . ";\n";
        if (@file_put_contents(CONFIG_FILE, $php, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir inc/config.php. Revisa los permisos de la carpeta inc/.');
        }
        @chmod(CONFIG_FILE, 0600);
        if (!is_dir(UPLOAD_DIR)) {
            @mkdir(UPLOAD_DIR, 0755, true);
        }
        $done = true;
        $installed = true;
    } catch (PDOException $ex) {
        $error = 'No se pudo conectar a MySQL: ' . $ex->getMessage();
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Instalación — Vestidos para Quinceañeras Brooklyn</title>
<link rel="stylesheet" href="admin/admin.css">
</head>
<body class="auth">
<main class="auth-card">
  <img src="assets/img/logo.png" alt="Vestidos para Quinceañeras Brooklyn" class="auth-logo">
  <?php if ($done): ?>
    <h1>¡Instalación completa!</h1>
    <p class="ok">Se crearon las tablas y se cargó todo el contenido del sitio.</p>
    <p>Contraseña inicial del panel: <strong><?= e(DEFAULT_ADMIN_PASSWORD) ?></strong>. Cámbiala en <em>Ajustes → Seguridad</em>.</p>
    <p class="warn">Por seguridad, borra el archivo <code>install.php</code> del servidor.</p>
    <div class="row"><a class="btn" href="index.php">Ver el sitio</a><a class="btn btn-dark" href="admin/">Ir al panel</a></div>
  <?php elseif ($installed): ?>
    <h1>El sitio ya está instalado</h1>
    <p>Para reinstalar, borra <code>inc/config.php</code> en el servidor y vuelve a abrir esta página. Tu contenido en la base de datos no se borra.</p>
    <div class="row"><a class="btn" href="index.php">Ver el sitio</a><a class="btn btn-dark" href="admin/">Ir al panel</a></div>
  <?php else: ?>
    <h1>Instalar la base de datos</h1>
    <p>Datos de la base MySQL creada en Hostinger (hPanel → Bases de datos MySQL).</p>
    <?php if ($error): ?><p class="err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="stack">
      <label>Servidor MySQL<input name="db_host" value="<?= e($form['db_host']) ?>" required></label>
      <label>Nombre de la base de datos<input name="db_name" value="<?= e($form['db_name']) ?>" required></label>
      <label>Usuario<input name="db_user" value="<?= e($form['db_user']) ?>" required></label>
      <label>Contraseña<input name="db_pass" type="password" autocomplete="off" required></label>
      <button class="btn" type="submit">Instalar</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
