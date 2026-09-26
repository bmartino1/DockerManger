<?php
declare(strict_types=1);

namespace DockerManger;

final class Compose
{
    public function __construct(private readonly string $stacksDir)
    {
    }

    public function stacks(): array
    {
        if (!is_dir($this->stacksDir)) {
            return [];
        }

        $items = [];
        $dirs = glob(rtrim($this->stacksDir, '/') . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($dirs as $dir) {
            $composeFile = $this->findComposeFile($dir);
            if ($composeFile === null) {
                continue;
            }

            $name = basename($dir);
            $items[] = [
                'name' => $name,
                'path' => $dir,
                'compose_file' => basename($composeFile),
                'valid' => $this->validate($dir),
            ];
        }

        usort($items, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));
        return $items;
    }

    private function findComposeFile(string $dir): ?string
    {
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'] as $file) {
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private function validate(string $dir): bool
    {
        $command = 'cd ' . escapeshellarg($dir)
            . ' && docker compose config --quiet >/dev/null 2>&1';
        exec($command, $output, $code);
        return $code === 0;
    }
}
