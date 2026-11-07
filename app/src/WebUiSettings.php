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

    public function save(string $stack, string $url, bool $keepEmpty = false): array
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
            if ($url === '' && !$keepEmpty) unset($data[$stack]);
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

    /**
     * Exactly one optional WebUI per app.
     *
     * Managed stacks use their saved Compose service labels as authoritative.
     * A missing label in a valid Compose file means no WebUI, even if an old
     * running container still has the label. Manual settings are for apps
     * without a Compose WebUI label.
     */
    public function url(array $container, ?array $composeLabels = null): string
    {
        $labels = $composeLabels ?? ($container['labels'] ?? []);
        $configured = trim((string)($labels['com.dockermanger.webui'] ?? ''));
        if ($configured !== '') {
            try { return self::validateUrl($configured); }
            catch (InvalidArgumentException) { return ''; }
        }

        $settings = $this->read();
        $key = $this->appKey($container);
        if (array_key_exists($key, $settings)) {
            $manual = $settings[$key];
            if (!is_string($manual) || $manual === '') return '';
            try { return self::validateUrl($manual); }
            catch (InvalidArgumentException) { return ''; }
        }
        return '';
    }

    /** A blank URL explicitly disables the app's manually configured URL. */
    public function saveApp(array $container, string $url): array
    {
        $url = trim($url);
        // Keep an empty string as a tombstone instead of deleting the key.
        return $this->save($this->appKey($container), $url, true);
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
