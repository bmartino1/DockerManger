<?php

declare(strict_types=1);

namespace DockerManger;

use InvalidArgumentException;
use RuntimeException;

/**
 * DockerManger-only browser shortcuts. Never mutates Compose or container env.
 * Stored under the application's already-persistent /data bind mount.
 */
final class WebUiSettings
{
    private const FILE = '/data/config/webui.json';

    public function get(string $stack): string
    {
        $stack = $this->validateStack($stack);
        $settings = $this->read();
        return (string) ($settings[$stack] ?? '');
    }

    public function save(string $stack, string $url): array
    {
        $stack = $this->validateStack($stack);
        $url = trim($url);
        if ($url !== '') $url = self::validateUrl($url);

        $dir = dirname(self::FILE);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create DockerManger settings directory. Check /data permissions.');
        }
        $handle = @fopen(self::FILE, 'c+');
        if ($handle === false) throw new RuntimeException('Cannot write /data/config/webui.json.');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Cannot lock WebUI settings.');
            $raw = stream_get_contents($handle);
            $data = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : [];
            if (!is_array($data) || array_is_list($data) && $data !== []) {
                throw new RuntimeException('Invalid WebUI settings file; refusing to overwrite.');
            }
            if ($url === '') unset($data[$stack]);
            else $data[$stack] = $url;
            $encoded = json_encode($data === [] ? new \stdClass() : $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            rewind($handle);
            if (fwrite($handle, $encoded . "\n") !== strlen($encoded) + 1) {
                throw new RuntimeException('Unable to save WebUI settings.');
            }
            if (!ftruncate($handle, strlen($encoded) + 1)) throw new RuntimeException('Unable to truncate WebUI settings.');
            fflush($handle);
            @chmod(self::FILE, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        return ['ok' => true, 'url' => $url];
    }

    /** Discover validated shortcuts from Compose service labels plus saved per-app overrides. */
    public function links(array $container): array
    {
        $links = [];
        foreach (($container['labels'] ?? []) as $key => $value) {
            if ($key !== 'com.dockermanger.webui' && !preg_match('/^com\\.dockermanger\\.webui\\.[A-Za-z0-9_-]+$/', (string)$key)) continue;
            try {
                $url = self::validateUrl(trim((string)$value));
                $label = $key === 'com.dockermanger.webui' ? 'WebUI' : substr((string)$key, strlen('com.dockermanger.webui.'));
                $links[$label] = $url;
            } catch (InvalidArgumentException) { /* Ignore unsafe or malformed label URLs. */ }
        }
        $base = $this->appKey($container);
        foreach ($this->read() as $key => $value) {
            if ($key !== $base && !str_starts_with((string)$key, $base . ':')) continue;
            $label = $key === $base ? 'WebUI' : substr((string)$key, strlen($base) + 1);
            if (!is_string($value) || !preg_match('/^(WebUI|[A-Za-z0-9_-]{1,48})$/', $label)) continue;
            try { $links[$label] = self::validateUrl($value); }
            catch (InvalidArgumentException) { /* Ignore stale invalid stored URLs. */ }
        }
        return $links;
    }

    /** A blank URL removes only the selected override and restores any Compose label. */
    public function saveApp(array $container, string $url, string $label = 'WebUI'): array
    {
        if (!preg_match('/^(WebUI|[A-Za-z0-9_-]{1,48})$/', $label)) {
            throw new InvalidArgumentException('Invalid WebUI shortcut name.');
        }
        $key = $this->appKey($container) . ($label === 'WebUI' ? '' : ':' . $label);
        return $this->save($key, $url);
    }

    private function appKey(array $container): string
    {
        $project = Docker::composeProject($container);
        $service = trim((string)($container['labels']['com.docker.compose.service'] ?? ''));
        $key = $project !== null && $service !== ''
            ? 'app:' . $project . ':' . $service
            : 'app:container:' . (string)($container['name'] ?? '');
        if (strlen($key) > 200 || !preg_match('/^app:[A-Za-z0-9_.:-]+$/', $key)) {
            throw new InvalidArgumentException('Invalid app identity.');
        }
        return $key;
    }

    private function read(): array
    {
        if (!is_file(self::FILE)) return [];
        $handle = @fopen(self::FILE, 'r');
        if ($handle === false) throw new RuntimeException('Cannot read WebUI settings.');
        try {
            if (!flock($handle, LOCK_SH)) throw new RuntimeException('Cannot lock WebUI settings.');
            $raw = stream_get_contents($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) throw new RuntimeException('Invalid WebUI settings JSON.');
        return $data;
    }

    private function validateStack(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,250}$/', $name) || $name === '.' || $name === '..') {
            throw new InvalidArgumentException('Invalid stack name.');
        }
        return $name;
    }

    public static function validateUrl(string $url): string
    {
        if (strlen($url) > 2048 || preg_match('/[\\x00-\\x20\\x7f]/', $url)) {
            throw new InvalidArgumentException('WebUI URL contains whitespace or invalid characters.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/["\'\\\\]/', $url)) {
            throw new InvalidArgumentException('Enter a valid http:// or https:// WebUI URL without credentials.');
        }
        return $url;
    }
}
