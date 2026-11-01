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
            'nodeVersion' => $this->runtimeVersion('node'),
            'npmVersion' => $this->runtimeVersion('npm'),
            'timezone' => date_default_timezone_get(),
            'stacksDir' => getenv('STACKS_DIR') ?: '/opt/stacks',
        ];
    }

    /** Read the installed CLI version without invoking a shell supplied by the browser. */
    private function runtimeVersion(string $binary): string
    {
        $result = Command::run($binary, ['--version'], null, 3);
        if ($result['exitCode'] !== 0) return 'Unavailable';
        $version = trim($result['stdout']);
        return preg_match('/^v?[0-9]+(?:\.[0-9]+){1,3}(?:[-+][A-Za-z0-9.\-]+)?$/', $version)
            ? ltrim($version, 'v')
            : 'Unavailable';
    }
}
