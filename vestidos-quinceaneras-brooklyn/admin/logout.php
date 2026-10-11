<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/bootstrap.php';
start_session();
$_SESSION = [];
session_destroy();
header('Location: ../index.php');
