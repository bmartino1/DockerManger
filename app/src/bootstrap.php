<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'DockerManger\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

$timezone = getenv('TZ') ?: 'UTC';
if (!@date_default_timezone_set($timezone)) date_default_timezone_set('UTC');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('dockermanger');
    session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/']);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $token = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        throw new RuntimeException('Invalid or missing request token. Refresh the page and try again.');
    }
}
