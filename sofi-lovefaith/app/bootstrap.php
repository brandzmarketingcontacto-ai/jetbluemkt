<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('APP', __DIR__);
const APP_VERSION = '1.0.0';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('America/Denver');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('sofi_sess');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]);
session_start();

require APP . '/helpers.php';
require APP . '/db.php';
require APP . '/lang.php';
require APP . '/mailer.php';
require APP . '/stripe.php';
require APP . '/media.php';
require APP . '/shop.php';
require APP . '/blocks.php';

$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
    header('Location: ' . url('/install.php'));
    exit;
}
$CONFIG = require $cfgFile;
if (!empty($CONFIG['timezone'])) date_default_timezone_set($CONFIG['timezone']);
if (!empty($CONFIG['debug'])) ini_set('display_errors', '1');

try {
    db();
} catch (Throwable $ex) {
    http_response_code(500);
    error_log('[db] ' . $ex->getMessage());
    exit('<h1>Base de datos no disponible</h1><p>Revisa los datos de conexión en config.php.</p>');
}

if ($tz = setting('timezone', '')) @date_default_timezone_set($tz);

function is_admin(): bool
{
    return !empty($_SESSION['admin']) && ($_SESSION['admin_ver'] ?? '') === substr((string)setting('admin_password_hash', ''), -12);
}
