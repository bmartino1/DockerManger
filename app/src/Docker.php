<?php

declare(strict_types=1);

namespace DockerManger;

/**
 * Small, controlled Docker CLI interface.
 *
 * This class deliberately exposes named Docker operations instead of accepting
 * arbitrary command strings from HTTP requests.
 */
final class Docker
{
    private string $binary;

    public function __construct(string $binary = 'docker')
    {
        $this->binary = $binary;
    }

    /**
     * Return true when the Docker CLI can communicate with the Docker Engine.
     */
    public function available(): bool
    {
        $result = Command::run(
            $this->binary,
            ['version', '--format', '{{.Server.Version}}'],
            null,
            5
        );

        return $result['exitCode'] === 0 && trim($result['stdout']) !== '';
    }

    /**
     * Return basic Docker client/server information for the dashboard.
     *
     * @return array{
     *   available:bool,
     *   clientVersion:?string,
     *   serverVersion:?string,
     *   error:?string
     * }
     */
    public function info(): array
    {
        $client = Command::run(
            $this->binary,
            ['version', '--format', '{{.Client.Version}}'],
            null,
            5
        );

        $server = Command::run(
            $this->binary,
            ['version', '--format', '{{.Server.Version}}'],
            null,
            5
        );

        $available = $server['exitCode'] === 0 && trim($server['stdout']) !== '';

        return [
            'available' => $available,
            'clientVersion' => $client['exitCode'] === 0 ? trim($client['stdout']) : null,
            'serverVersion' => $available ? trim($server['stdout']) : null,
            'error' => $available ? null : ($server['output'] ?: 'Docker Engine unavailable.'),
        ];
    }

    /**
     * List all containers, including stopped containers.
     *
     * Docker's JSON formatter gives us structured records without fragile
     * whitespace parsing.
     *
     * @return array<int,array<string,mixed>>
     */
    public function containers(): array
    {
        $result = Command::run(
            $this->binary,
            [
                'ps',
                '-a',
                '--no-trunc',
                '--format',
                '{{json .}}',
            ],
            null,
            10
        );

        if ($result['exitCode'] !== 0 || $result['stdout'] === '') {
            return [];
        }

        $containers = [];

        foreach (preg_split('/\R/', $result['stdout']) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $raw = json_decode($line, true);

            if (!is_array($raw)) {
                continue;
            }

            $state = strtolower((string) ($raw['State'] ?? 'unknown'));

            $containers[] = [
                'id' => (string) ($raw['ID'] ?? ''),
                'name' => (string) ($raw['Names'] ?? ''),
                'image' => (string) ($raw['Image'] ?? ''),
                'state' => $state,
                'status' => (string) ($raw['Status'] ?? ''),
                'ports' => (string) ($raw['Ports'] ?? ''),
                'createdAt' => (string) ($raw['CreatedAt'] ?? ''),
                'labels' => $this->parseLabels((string) ($raw['Labels'] ?? '')),
                'running' => $state === 'running',
            ];
        }

        usort(
            $containers,
            static fn(array $a, array $b): int =>
                strcasecmp((string) $a['name'], (string) $b['name'])
        );

        return $containers;
    }

    /**
     * Return the Compose project name reported by Docker labels, if present.
     */
    public static function composeProject(array $container): ?string
    {
        $labels = $container['labels'] ?? [];

        if (!is_array($labels)) {
            return null;
        }

        $project = trim((string) ($labels['com.docker.compose.project'] ?? ''));

        return $project !== '' ? $project : null;
    }

    /**
     * @return array<string,string>
     */
    private function parseLabels(string $labels): array
    {
        $parsed = [];

        if ($labels === '') {
            return $parsed;
        }

        foreach (explode(',', $labels) as $label) {
            $label = trim($label);

            if ($label === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $label, 2), 2, '');

            if ($key !== '') {
                $parsed[$key] = $value;
            }
        }

        return $parsed;
    }
}
