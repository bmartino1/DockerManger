<?php

declare(strict_types=1);

namespace DockerManger;

/**
 * Safe, read-only system/runtime information used by the dashboard.
 */
final class SystemInfo
{
    /**
     * @return array<string,mixed>
     */
    public function get(): array
    {
        return [
            'hostname' => gethostname() ?: 'unknown',
            'architecture' => php_uname('m'),
            'kernel' => php_uname('r'),
            'phpVersion' => PHP_VERSION,
            'timezone' => date_default_timezone_get(),
            'stacksDir' => getenv('STACKS_DIR') ?: '/opt/stacks',
        ];
    }
}
