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

    /** One bounded Docker sample, with no background collection. */
    public function stats(): array
    {
        $result = Command::run($this->binary, ['stats', '--no-stream', '--format', '{{json .}}'], null, 15);
        if ($result['exitCode'] !== 0) throw new RuntimeException($result['output'] ?: 'Unable to sample Docker stats.');
        $records = [];
        foreach (preg_split('/\\R/', trim($result['stdout'])) ?: [] as $line) {
            $raw = json_decode($line, true);
            if (!is_array($raw)) continue;
            $records[] = [
                'name' => (string) ($raw['Name'] ?? ''),
                'cpuPercent' => max(0.0, (float) ($raw['CPUPerc'] ?? 0)),
                'memoryPercent' => max(0.0, (float) ($raw['MemPerc'] ?? 0)),
                'memoryUsage' => (string) ($raw['MemUsage'] ?? ''),
            ];
        }
        return $records;
    }

    /** Return local Docker images with conservative usage information. */
    public function images(): array
    {
        $result = Command::run($this->binary, ['image', 'ls', '-a', '--no-trunc', '--format', '{{json .}}'], null, 15);
        if ($result['exitCode'] !== 0 || trim($result['stdout']) === '') return [];

        $used = [];
        $containers = Command::run($this->binary, ['ps', '-a', '--no-trunc', '--format', '{{.ImageID}}'], null, 10);
        if ($containers['exitCode'] === 0) {
            foreach (preg_split('/\\R/', trim($containers['stdout'])) ?: [] as $id) {
                $id = trim($id);
                if ($id !== '') $used[$id] = ($used[$id] ?? 0) + 1;
            }
        }

        $images = [];
        foreach (preg_split('/\\R/', trim($result['stdout'])) ?: [] as $line) {
            $raw = json_decode($line, true);
            if (!is_array($raw)) continue;
            $id = (string) ($raw['ID'] ?? '');
            $images[] = [
                'id' => $id,
                'repository' => (string) ($raw['Repository'] ?? ''),
                'tag' => (string) ($raw['Tag'] ?? ''),
                'createdSince' => (string) ($raw['CreatedSince'] ?? ''),
                'createdAt' => (string) ($raw['CreatedAt'] ?? ''),
                'size' => (string) ($raw['Size'] ?? ''),
                'containers' => (int) ($used[$id] ?? 0),
                'dangling' => (($raw['Repository'] ?? '') === '<none>' || ($raw['Tag'] ?? '') === '<none>'),
            ];
        }
        usort($images, static fn(array $a, array $b): int => strcasecmp($a['repository'] . ':' . $a['tag'], $b['repository'] . ':' . $b['tag']));
        return $images;
    }

    /** Docker-managed volumes only; bind mounts are never included. */
    public function volumes(): array
    {
        $listed = Command::run($this->binary, ['volume', 'ls', '--format', '{{json .}}'], null, 15);
        if ($listed['exitCode'] !== 0) throw new RuntimeException($listed['output'] ?: 'Unable to list volumes.');
        $volumes = [];
        foreach (preg_split('/\\R/', trim($listed['stdout'])) ?: [] as $line) {
            $raw = json_decode($line, true);
            if (!is_array($raw) || empty($raw['Name'])) continue;
            $name = (string)$raw['Name'];
            $volumes[$name] = [
                'name' => $name,
                'driver' => (string)($raw['Driver'] ?? ''),
                'mountpoint' => '',
                'createdAt' => '',
                'labels' => (string)($raw['Labels'] ?? ''),
                'usedBy' => [],
            ];
        }
        if ($volumes === []) return [];

        // Include stopped containers: a stopped container can still own its volume.
        $ids = Command::run($this->binary, ['ps', '-aq', '--no-trunc'], null, 15);
        if ($ids['exitCode'] !== 0) throw new RuntimeException('Unable to determine container volume usage.');
        $containerIds = array_values(array_filter(preg_split('/\\s+/', trim($ids['stdout'])) ?: []));
        foreach (array_chunk($containerIds, 40) as $chunk) {
            $inspected = Command::run($this->binary, array_merge(['inspect'], $chunk), null, 30);
            if ($inspected['exitCode'] !== 0) throw new RuntimeException('Unable to inspect volume consumers.');
            $data = json_decode($inspected['stdout'], true);
            if (!is_array($data)) throw new RuntimeException('Invalid Docker container inspection response.');
            foreach ($data as $container) {
                if (!is_array($container)) continue;
                $name = ltrim((string)($container['Name'] ?? ''), '/');
                foreach (($container['Mounts'] ?? []) as $mount) {
                    if (!is_array($mount) || ($mount['Type'] ?? '') !== 'volume') continue;
                    $volumeName = (string)($mount['Name'] ?? '');
                    if (isset($volumes[$volumeName])) $volumes[$volumeName]['usedBy'][] = $name;
                }
            }
        }
        foreach (array_chunk(array_keys($volumes), 40) as $chunk) {
            $inspected = Command::run($this->binary, array_merge(['volume', 'inspect'], $chunk), null, 30);
            if ($inspected['exitCode'] !== 0) throw new RuntimeException('Unable to inspect Docker volumes.');
            $data = json_decode($inspected['stdout'], true);
            if (!is_array($data)) throw new RuntimeException('Invalid Docker volume inspection response.');
            foreach ($data as $volume) {
                if (!is_array($volume)) continue;
                $name = (string)($volume['Name'] ?? '');
                if (!isset($volumes[$name])) continue;
                $volumes[$name]['mountpoint'] = (string)($volume['Mountpoint'] ?? '');
                $volumes[$name]['createdAt'] = (string)($volume['CreatedAt'] ?? '');
            }
        }
        ksort($volumes, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($volumes);
    }

    /** Create a named Docker local-driver volume without browser-selected flags. */
    public function createVolume(string $name): array
    {
        $name = $this->assertVolumeName($name);
        return Command::run($this->binary, ['volume', 'create', '--driver', 'local', $name], null, 120);
    }

    /** No force removal: Docker will also refuse volumes referenced by containers. */
    public function removeVolume(string $name): array
    {
        $name = $this->assertVolumeName($name);
        return Command::run($this->binary, ['volume', 'rm', $name], null, 120);
    }

    /** Docker's default prune only removes unused anonymous volumes. */
    public function pruneVolumes(bool $all = false): array
    {
        $args = ['volume', 'prune', '-f'];
        if ($all) $args[] = '-a';
        return Command::run($this->binary, $args, null, 300);
    }

    private function assertVolumeName(string $name): string
    {
        $name = trim($name);
        if (strlen($name) > 255 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name)) {
            throw new InvalidArgumentException('Invalid Docker volume name.');
        }
        return $name;
    }

    public function pullImage(string $reference): array
    {
        $reference = $this->assertImageReference($reference);
        return Command::run($this->binary, ['pull', $reference], null, 900);
    }

    public function removeImage(string $identifier): array
    {
        $identifier = $this->assertImageIdentifier($identifier);
        return Command::run($this->binary, ['image', 'rm', $identifier], null, 120);
    }

    public function pruneImages(bool $all = false): array
    {
        $args = ['image', 'prune', '-f'];
        if ($all) $args[] = '-a';
        return Command::run($this->binary, $args, null, 300);
    }

    public function pruneBuilderCache(): array
    {
        return Command::run($this->binary, ['builder', 'prune', '-f'], null, 300);
    }

    public function systemPrune(): array
    {
        return Command::run($this->binary, ['system', 'prune', '-f'], null, 300);
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

    private function assertImageIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if (!preg_match('/^(?:sha256:)?[a-f0-9]{12,64}$/i', $identifier)) {
            throw new InvalidArgumentException('Invalid image identifier.');
        }
        return $identifier;
    }

    private function assertImageReference(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 255 || preg_match('/\\s/', $reference) || str_starts_with($reference, '-')) {
            throw new InvalidArgumentException('Invalid image reference.');
        }
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/:@-]*$#', $reference)) {
            throw new InvalidArgumentException('Invalid image reference.');
        }
        return $reference;
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
