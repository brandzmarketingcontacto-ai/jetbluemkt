<?php
/**
 * Instalador de Sofi Love & Faith.
 * 1) Crea las tablas en la base de datos MySQL de Hostinger.
 * 2) Carga ajustes, páginas y productos de ejemplo.
 * 3) Escribe config.php con los datos de conexión.
 * Después de instalar queda bloqueado (install.lock).
 */
declare(strict_types=1);

define('ROOT', __DIR__);
define('APP', __DIR__ . '/app');
session_start();
require APP . '/helpers.php';
require APP . '/db.php';
require APP . '/seed.php';

$lock = ROOT . '/install.lock';
$errors = [];
$done = false;
$installed = is_file($lock) && is_file(ROOT . '/config.php');

$defaults = [
    'db_host' => 'localhost',
    'db_name' => 'u731277802_sofidb',
    'db_user' => 'u731277802_sofilf',
    'db_pass' => '',
    'admin_pass' => 'admin123',
    'demo' => '1',
];
$f = $defaults;

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($_SESSION['install_csrf'] ?? '', (string)($_POST['_csrf'] ?? ''))) {
        $errors[] = 'La sesión expiró. Vuelve a enviar el formulario.';
    } else {
        foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'admin_pass'] as $k) $f[$k] = trim((string)($_POST[$k] ?? ''));
        $f['demo'] = !empty($_POST['demo']) ? '1' : '';
        if ($f['db_name'] === '' || $f['db_user'] === '') $errors[] = 'Escribe el nombre de la base de datos y el usuario.';
        if (strlen($f['admin_pass']) < 6) $errors[] = 'La contraseña del panel debe tener al menos 6 caracteres.';
        if (!is_writable(ROOT)) $errors[] = 'La carpeta del sitio no tiene permisos de escritura (necesaria para crear config.php).';
        if (!$errors) {
            $CONFIG = ['db_host' => $f['db_host'] ?: 'localhost', 'db_port' => 3306, 'db_name' => $f['db_name'], 'db_user' => $f['db_user'], 'db_pass' => $f['db_pass']];
            try {
                $pdo = db_connect($CONFIG);
                $sql = (string)file_get_contents(APP . '/schema.sql');
                $sql = preg_replace('/^--.*$/m', '', $sql);
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) $pdo->exec($stmt);
                $GLOBALS['CONFIG'] = $CONFIG;
                seed_defaults($f['admin_pass'], (bool)$f['demo']);

                $cfg = $CONFIG + ['app_key' => bin2hex(random_bytes(16)), 'timezone' => 'America/Denver', 'debug' => false];
                $php = "<?php\n// Generado por install.php el " . date('Y-m-d H:i') . ". No compartas este archivo.\nreturn " . var_export($cfg, true) . ";\n";
                if (file_put_contents(ROOT . '/config.php', $php) === false) throw new RuntimeException('No se pudo escribir config.php');
                @chmod(ROOT . '/config.php', 0640);
                file_put_contents($lock, date('c'));
                foreach (['uploads'] as $dir) if (!is_dir(ROOT . '/' . $dir)) @mkdir(ROOT . '/' . $dir, 0755, true);
                $done = true;
            } catch (PDOException $ex) {
                $msg = $ex->getMessage();
                if (str_contains($msg, 'Access denied')) $errors[] = 'Acceso denegado: revisa el usuario y la contraseña de MySQL en hPanel › Bases de datos.';
                elseif (str_contains($msg, 'Unknown database')) $errors[] = 'La base de datos no existe. Revisa el nombre completo (incluye el prefijo u731277802_).';
                elseif (str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Connection refused')) $errors[] = 'No se pudo conectar al servidor MySQL. En Hostinger el host es "localhost".';
                else $errors[] = 'Error de base de datos: ' . $msg;
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }
}
$_SESSION['install_csrf'] = $_SESSION['install_csrf'] ?? bin2hex(random_bytes(16));
$checks = [
    'PHP 8.1 o superior' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Extensión PDO MySQL' => extension_loaded('pdo_mysql'),
    'Extensión cURL (Stripe)' => extension_loaded('curl'),
    'Extensión GD (imágenes)' => extension_loaded('gd'),
    'Extensión fileinfo' => extension_loaded('fileinfo'),
    'Carpeta uploads con permisos de escritura' => is_writable(ROOT . '/uploads'),
];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Instalar — Sofi Love &amp; Faith</title>
<link href="https://fonts.googleapis.com/css2?family=Great+Vibes&family=Jost:wght@400;500;600&display=swap" rel="stylesheet">
<style>
body{margin:0;background:#F7F1F3;font-family:Jost,system-ui,sans-serif;color:#2E1F25;font-size:15px}
.box{max-width:560px;margin:40px auto;background:#fff;border:1px solid #ECE2E5;border-radius:28px;padding:40px;box-shadow:0 30px 70px rgba(75,38,53,.1)}
h1{margin:6px 0 4px;font-size:24px}.script{font-family:'Great Vibes',cursive;color:#B83A6B;font-size:34px;line-height:1}
.head{text-align:center;display:flex;flex-direction:column;align-items:center;gap:6px}
.head img{width:96px;height:96px;border-radius:50%;object-fit:cover}
label{display:flex;flex-direction:column;gap:6px;font-weight:500;margin-top:14px}
input[type=text],input[type=password]{height:46px;padding:0 14px;border:1px solid #DCC9CF;border-radius:12px;font:inherit}
.hint{font-weight:400;color:#6E5A61;font-size:13px}
.row{display:flex;gap:10px;align-items:center;font-weight:400;flex-direction:row}
button,.btn{margin-top:22px;width:100%;height:52px;border:0;border-radius:14px;background:#B83A6B;color:#fff;font:600 16px Jost,sans-serif;cursor:pointer;display:flex;align-items:center;justify-content:center;text-decoration:none}
.err{background:#FBE9E7;color:#A1281D;border-radius:12px;padding:12px 14px;margin-top:14px}
.ok{background:#E6F2EA;color:#1F6B3C;border-radius:12px;padding:14px;margin-top:14px;line-height:1.6}
.checks{list-style:none;padding:0;margin:18px 0 0;display:grid;gap:6px;font-size:14px}
.checks li{display:flex;gap:8px}.y{color:#1F6B3C}.n{color:#A1281D}
fieldset{border:1px solid #ECE2E5;border-radius:16px;padding:6px 18px 18px;margin:20px 0 0}
legend{font-weight:600;padding:0 6px}
.warn{background:#FBF0DD;color:#6B4A12;border-radius:12px;padding:12px 14px;margin-top:16px;font-size:14px}
</style>
</head>
<body>
<div class="box">
  <div class="head">
    <img src="assets/img/logo.jpg" alt="Sofi Love &amp; Faith">
    <span class="script">Sofi Love &amp; Faith</span>
    <h1>Instalación del sitio</h1>
    <p class="hint">Conecta la base de datos MySQL de Hostinger y crea la tienda.</p>
  </div>

  <?php if ($installed && !$done): ?>
    <div class="ok">El sitio ya está instalado. Por seguridad, este instalador está bloqueado.<br>Para reinstalar, borra <code>install.lock</code> y <code>config.php</code> desde el Administrador de archivos.</div>
    <a class="btn" href="admin">Ir al panel de administración</a>
  <?php elseif ($done): ?>
    <div class="ok"><strong>¡Listo! La base de datos quedó instalada.</strong><br>
      Se crearon las tablas, los ajustes, las páginas (Inicio, Tienda, Contacto)<?= $f['demo'] ? ', los productos de ejemplo con tus fotos' : '' ?> y los cupones FAITH25 y WELCOME10.<br>
      Contraseña del panel: <strong><?= e($f['admin_pass']) ?></strong> (cámbiala en Ajustes › Seguridad).</div>
    <div class="warn">Recomendado: borra el archivo <code>install.php</code> desde el Administrador de archivos de Hostinger.</div>
    <a class="btn" href="./">Ver la tienda</a>
    <a class="btn" style="background:#4B2635" href="admin">Entrar al panel</a>
  <?php else: ?>
    <ul class="checks">
      <?php foreach ($checks as $label => $ok): ?><li class="<?= $ok ? 'y' : 'n' ?>"><?= $ok ? '✓' : '✗' ?> <?= e($label) ?></li><?php endforeach; ?>
    </ul>
    <?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= e($_SESSION['install_csrf']) ?>">
      <fieldset>
        <legend>Base de datos MySQL (hPanel › Bases de datos)</legend>
        <label>Host<input type="text" name="db_host" value="<?= e($f['db_host']) ?>"><span class="hint">En Hostinger casi siempre es <strong>localhost</strong>.</span></label>
        <label>Nombre de la base de datos<input type="text" name="db_name" value="<?= e($f['db_name']) ?>" required></label>
        <label>Usuario MySQL<input type="text" name="db_user" value="<?= e($f['db_user']) ?>" required></label>
        <label>Contraseña MySQL<input type="password" name="db_pass" value="<?= e($f['db_pass']) ?>" autocomplete="new-password"></label>
      </fieldset>
      <fieldset>
        <legend>Panel de administración</legend>
        <label>Contraseña del panel<input type="text" name="admin_pass" value="<?= e($f['admin_pass']) ?>" required minlength="6"><span class="hint">El panel no pide usuario, solo esta contraseña.</span></label>
        <label class="row"><input type="checkbox" name="demo" value="1" <?= $f['demo'] ? 'checked' : '' ?>> Cargar productos de ejemplo (tus fotos de prueba)</label>
      </fieldset>
      <button type="submit">Instalar base de datos</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
