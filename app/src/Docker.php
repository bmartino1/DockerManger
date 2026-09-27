<?php

declare(strict_types=1);

namespace DockerManger;

use InvalidArgumentException;

/**
 * Controlled Docker CLI interface.
 *
 * HTTP code may choose from these named operations, but never supplies an
 * arbitrary Docker command. This keeps the web UI useful without turning the
 * API into a remote command endpoint.
 */
final class Docker
{
    public function __construct(private string $binary = 'docker') {}

    public function available(): bool
    {
        return $this->info()['available'];
    }

    public function info(): array
    {
        $client = Command::run($this->binary, ['version', '--format', '{{.Client.Version}}'], null, 5);
        $server = Command::run($this->binary, ['version', '--format', '{{.Server.Version}}'], null, 5);
        $available = $server['exitCode'] === 0 && trim($server['stdout']) !== '';

        return [
            'available' => $available,
            'clientVersion' => $client['exitCode'] === 0 ? trim($client['stdout']) : null,
            'serverVersion' => $available ? trim($server['stdout']) : null,
            'error' => $available ? null : ($server['output'] ?: 'Docker Engine unavailable.'),
        ];
    }

    public function containers(): array
    {
        $result = Command::run($this->binary, ['ps', '-a', '--no-trunc', '--format', '{{json .}}'], null, 10);
        if ($result['exitCode'] !== 0 || $result['stdout'] === '') return [];

        $containers = [];
        foreach (preg_split('/\R/', $result['stdout']) ?: [] as $line) {
            $raw = json_decode(trim($line), true);
            if (!is_array($raw)) continue;
            $state = strtolower((string)($raw['State'] ?? 'unknown'));
            $containers[] = [
                'id' => (string)($raw['ID'] ?? ''),
                'name' => (string)($raw['Names'] ?? ''),
                'image' => (string)($raw['Image'] ?? ''),
                'state' => $state,
                'status' => (string)($raw['Status'] ?? ''),
                'ports' => (string)($raw['Ports'] ?? ''),
                'createdAt' => (string)($raw['CreatedAt'] ?? ''),
                'labels' => $this->parseLabels((string)($raw['Labels'] ?? '')),
                'running' => $state === 'running',
            ];
        }
        usort($containers, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $containers;
    }

    public function container(string $identifier): ?array
    {
        $identifier = $this->assertContainerIdentifier($identifier);
        foreach ($this->containers() as $container) {
            if ($container['id'] === $identifier || str_starts_with($container['id'], $identifier) || $container['name'] === $identifier) {
                return $container;
            }
        }
        return null;
    }

    public function start(string $identifier): array { return $this->containerAction('start', $identifier); }
    public function stop(string $identifier): array { return $this->containerAction('stop', $identifier); }
    public function restart(string $identifier): array { return $this->containerAction('restart', $identifier); }

    public function logs(string $identifier, int $tail = 250): array
    {
        $identifier = $this->assertContainerIdentifier($identifier);
        $tail = max(10, min($tail, 2000));
        return Command::run($this->binary, ['logs', '--tail', (string)$tail, '--timestamps', $identifier], null, 15);
    }

    private function containerAction(string $action, string $identifier): array
    {
        $identifier = $this->assertContainerIdentifier($identifier);
        return Command::run($this->binary, [$action, $identifier], null, 60);
    }

    private function assertContainerIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $identifier)) {
            throw new InvalidArgumentException('Invalid container identifier.');
        }
        return $identifier;
    }

    public static function composeProject(array $container): ?string
    {
        $labels = $container['labels'] ?? [];
        if (!is_array($labels)) return null;
        $project = trim((string)($labels['com.docker.compose.project'] ?? ''));
        return $project !== '' ? $project : null;
    }

    private function parseLabels(string $labels): array
    {
        $parsed = [];
        foreach ($labels === '' ? [] : explode(',', $labels) as $label) {
            [$key, $value] = array_pad(explode('=', trim($label), 2), 2, '');
            if ($key !== '') $parsed[$key] = $value;
        }
        return $parsed;
    }
}
