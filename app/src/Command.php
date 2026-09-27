<?php

declare(strict_types=1);

namespace DockerManger;

/**
 * Controlled subprocess execution helper.
 *
 * DockerManger intentionally uses the Docker and Docker Compose CLIs instead
 * of talking directly to the Docker socket API. This class centralizes process
 * execution so command construction is not duplicated throughout the app.
 *
 * IMPORTANT:
 * - Callers supply an executable and an array of individual arguments.
 * - Arguments are escaped individually.
 * - This is NOT an arbitrary shell-command API and must never be exposed as
 *   one through api.php.
 */
final class Command
{
    /**
     * Execute a command and capture stdout/stderr.
     *
     * @param string        $executable Absolute or PATH-resolved executable.
     * @param array<int,string> $arguments Individual command arguments.
     * @param string|null   $cwd Optional working directory.
     * @param int           $timeoutSeconds Safety timeout.
     *
     * @return array{
     *   command:string,
     *   exitCode:int,
     *   stdout:string,
     *   stderr:string,
     *   output:string
     * }
     */
    public static function run(
        string $executable,
        array $arguments = [],
        ?string $cwd = null,
        int $timeoutSeconds = 30
    ): array {
        if ($timeoutSeconds < 1) {
            $timeoutSeconds = 1;
        }

        $parts = [escapeshellarg($executable)];

        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg((string) $argument);
        }

        $displayCommand = implode(' ', $parts);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $displayCommand,
            $descriptorSpec,
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => false]
        );

        if (!is_resource($process)) {
            return [
                'command' => $displayCommand,
                'exitCode' => 127,
                'stdout' => '',
                'stderr' => 'Unable to start process.',
                'output' => 'Unable to start process.',
            ];
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $timedOut = false;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $started) >= $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process, 15);
                usleep(250000);

                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }

                break;
            }

            usleep(50000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($timedOut) {
            $exitCode = 124;
            $stderr = trim($stderr . "\nCommand timed out.");
        }

        $stdout = trim($stdout);
        $stderr = trim($stderr);
        $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));

        return [
            'command' => $displayCommand,
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output' => $output,
        ];
    }
}
