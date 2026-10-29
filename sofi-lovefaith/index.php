<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$path = '/' . trim(substr(strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), strlen(base_path())), '/');
$path = rawurldecode($path);
if ($path === '/index.php') $path = '/';

if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    require APP . '/admin.php';
    admin_route(substr($path, 6) ?: '/');
    exit;
}

require APP . '/front.php';
front_route($path);
