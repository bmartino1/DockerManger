<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';
header('Cache-Control: no-store');
if (!\DockerManger\Auth::loggedIn()) {
    http_response_code(401);
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
echo 'ok';
