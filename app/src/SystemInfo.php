<?php
declare(strict_types=1);

namespace DockerManger;

final class SystemInfo
{
    public static function loadAverage(): array
    {
        $load = sys_getloadavg();
        return $load ?: [0, 0, 0];
    }

    public static function hostname(): string
    {
        return gethostname() ?: 'unknown';
    }
}
