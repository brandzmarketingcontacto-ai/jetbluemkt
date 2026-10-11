<?php
// Acceso al panel solo con contraseña (sin correo). Limita los intentos fallidos.

declare(strict_types=1);

require dirname(__DIR__) . '/inc/bootstrap.php';
require_installed('../install.php');
start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . (is_admin() ? './' : '../index.php?login=1'));
    exit;
}

$now = time();
$fails = array_filter($_SESSION['login_fails'] ?? [], fn($t) => $t > $now - 900);
if (count($fails) >= 5) {
    header('Location: ../index.php?login=error');
    exit;
}

$ok = check_csrf($_POST['csrf'] ?? null)
    && password_verify((string) ($_POST['password'] ?? ''), setting('admin_pass_hash'));

if (!$ok) {
    $fails[] = $now;
    $_SESSION['login_fails'] = array_values($fails);
    usleep(400000);
    header('Location: ../index.php?login=error');
    exit;
}

session_regenerate_id(true);
$_SESSION['admin'] = true;
$_SESSION['login_fails'] = [];
unset($_SESSION['csrf']);
header('Location: ./');
