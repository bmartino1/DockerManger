<?php
declare(strict_types=1);

namespace DockerManger;

final class Docker
{
    private function run(array $args): array
    {
        $command = 'docker';
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $command .= ' 2>&1';

        $output = [];
        $code = 0;
        exec($command, $output, $code);

        return [
            'ok' => $code === 0,
            'code' => $code,
            'output' => implode("\n", $output),
        ];
    }

    public function available(): bool
    {
        return is_file('/var/run/docker.sock') && $this->run(['info'])['ok'];
    }

    public function version(): string
    {
        $result = $this->run(['version', '--format', '{{.Server.Version}}']);
        return $result['ok'] ? trim($result['output']) : 'Unavailable';
    }

    public function containers(): array
    {
        $result = $this->run([
            'ps', '-a',
            '--format',
            '{{json .}}',
        ]);

        if (!$result['ok'] || trim($result['output']) === '') {
            return [];
        }

        $containers = [];
        foreach (preg_split('/\R/', trim($result['output'])) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $containers[] = $row;
            }
        }

        return $containers;
    }
}
