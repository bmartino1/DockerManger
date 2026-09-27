<?php

declare(strict_types=1);

/**
 * DockerManger application bootstrap.
 *
 * Keep startup intentionally small. DockerManger does not require Composer for
 * its own application classes; classes in app/src are loaded by namespace.
 */

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'DockerManger\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
);

$timezone = getenv('TZ') ?: 'UTC';

if (!@date_default_timezone_set($timezone)) {
    date_default_timezone_set('UTC');
}
