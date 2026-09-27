<?php

declare(strict_types=1);

namespace DockerManger;

use InvalidArgumentException;
use RuntimeException;

/**
 * Controlled Docker CLI interface.
 *
 * The browser/API may choose only from named operations implemented here. User
 * input is never accepted as a free-form Docker or shell command. The mounted
 * Docker socket is effectively root-equivalent, so this boundary is important
 * even before the authentication milestone is implemented.
 */
final class Docker
{
    public function __construct(private string $binary = 'docker') {}

    public function available(): bool
    {
        return $this->info()['available'];
    }

    /** Return Docker client/server connectivity information for the UI. */
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

    /** Return the compact container records used by dashboard and stack views. */
    public function containers(): array
    {
        $result = Command::run($this->binary, ['ps', '-a', '--no-trunc', '--format', '{{json .}}'], null, 10);
        if ($result['exitCode'] !== 0 || $result['stdout'] === '') {
            return [];
        }

        $containers = [];
        foreach (preg_split('/\R/', $result['stdout']) ?: [] as $line) {
            $raw = json_decode(trim($line), true);
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

        usort($containers, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $containers;
    }

    /** Resolve a container by full/partial ID or exact name. */
    public function container(string $identifier): ?array
    {
        $identifier = $this->assertContainerIdentifier($identifier);
        foreach ($this->containers() as $container) {
            if (
                $container['id'] === $identifier
                || str_starts_with($container['id'], $identifier)
                || $container['name'] === $identifier
            ) {
                return $container;
            }
        }
        return null;
    }

    /**
     * Return useful runtime metadata without exposing container environment
     * variables, which frequently contain passwords, tokens and API keys.
     */
    public function inspect(string $identifier): array
    {
        $identifier = $this->assertContainerIdentifier($identifier);
        $result = Command::run($this->binary, ['inspect', $identifier], null, 10);
        if ($result['exitCode'] !== 0) {
            throw new RuntimeException($result['output'] ?: 'Unable to inspect container.');
        }

        $decoded = json_decode($result['stdout'], true);
        $raw = is_array($decoded) && isset($decoded[0]) && is_array($decoded[0]) ? $decoded[0] : null;
        if ($raw === null) {
            throw new RuntimeException('Docker returned invalid container inspection data.');
        }

        $mounts = [];
        foreach (($raw['Mounts'] ?? []) as $mount) {
            if (!is_array($mount)) continue;
            $mounts[] = [
                'type' => (string) ($mount['Type'] ?? ''),
                'source' => (string) ($mount['Source'] ?? ''),
                'destination' => (string) ($mount['Destination'] ?? ''),
                'mode' => (string) ($mount['Mode'] ?? ''),
                'rw' => (bool) ($mount['RW'] ?? false),
            ];
        }

        $networks = [];
        foreach (($raw['NetworkSettings']['Networks'] ?? []) as $name => $network) {
            if (!is_array($network)) continue;
            $networks[] = [
                'name' => (string) $name,
                'ipAddress' => (string) ($network['IPAddress'] ?? ''),
                'gateway' => (string) ($network['Gateway'] ?? ''),
                'macAddress' => (string) ($network['MacAddress'] ?? ''),
            ];
        }

        return [
            'id' => (string) ($raw['Id'] ?? ''),
            'created' => (string) ($raw['Created'] ?? ''),
            'platform' => (string) ($raw['Platform'] ?? ''),
            'restartPolicy' => (string) ($raw['HostConfig']['RestartPolicy']['Name'] ?? 'no'),
            'privileged' => (bool) ($raw['HostConfig']['Privileged'] ?? false),
            'readOnlyRootfs' => (bool) ($raw['HostConfig']['ReadonlyRootfs'] ?? false),
            'mounts' => $mounts,
            'networks' => $networks,
        ];
    }

    public function start(string $identifier): array { return $this->containerAction('start', $identifier); }
    public function stop(string $identifier): array { return $this->containerAction('stop', $identifier); }
    public function restart(string $identifier): array { return $this->containerAction('restart', $identifier); }
    public function kill(string $identifier): array { return $this->containerAction('kill', $identifier); }

    public function logs(string $identifier, int $tail = 250): array
    {
        $identifier = $this->assertContainerIdentifier($identifier);
        $tail = max(10, min($tail, 2000));
        return Command::run($this->binary, ['logs', '--tail', (string) $tail, '--timestamps', $identifier], null, 15);
    }

    private function containerAction(string $action, string $identifier): array
    {
        $identifier = $this->assertContainerIdentifier($identifier);
        return Command::run($this->binary, [$action, $identifier], null, 60);
    }

    /** Restrict identifiers before they ever reach the Docker CLI. */
    private function assertContainerIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $identifier)) {
            throw new InvalidArgumentException('Invalid container identifier.');
        }
        return $identifier;
    }

    /** Compose project labels are how DockerManger associates containers to stacks. */
    public static function composeProject(array $container): ?string
    {
        $labels = $container['labels'] ?? [];
        if (!is_array($labels)) return null;
        $project = trim((string) ($labels['com.docker.compose.project'] ?? ''));
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
