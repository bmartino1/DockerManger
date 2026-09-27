<?php

declare(strict_types=1);

namespace DockerManger;

use DirectoryIterator;
use RuntimeException;

/**
 * Compose stack discovery and validation.
 *
 * Compose files remain the source of truth. DockerManger discovers immediate
 * child directories beneath STACKS_DIR and recognizes the standard Compose
 * filenames listed below.
 */
final class Compose
{
    private const FILENAMES = [
        'compose.yaml',
        'compose.yml',
        'docker-compose.yml',
        'docker-compose.yaml',
    ];

    private string $stacksDir;
    private string $dockerBinary;

    public function __construct(?string $stacksDir = null, string $dockerBinary = 'docker')
    {
        $configured = $stacksDir ?? (getenv('STACKS_DIR') ?: '/opt/stacks');
        $this->stacksDir = rtrim($configured, '/');
        $this->dockerBinary = $dockerBinary;
    }

    public function stacksDir(): string
    {
        return $this->stacksDir;
    }

    /**
     * Discover stack directories and validate each Compose file.
     *
     * @return array<int,array<string,mixed>>
     */
    public function stacks(): array
    {
        if (!is_dir($this->stacksDir) || !is_readable($this->stacksDir)) {
            return [];
        }

        $stacks = [];

        foreach (new DirectoryIterator($this->stacksDir) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }

            $directory = $entry->getPathname();
            $composeFile = $this->findComposeFile($directory);

            if ($composeFile === null) {
                continue;
            }

            $name = $entry->getFilename();
            $validation = $this->validate($directory, $composeFile);

            $stacks[] = [
                'name' => $name,
                'path' => $directory,
                'file' => basename($composeFile),
                'composeFile' => $composeFile,
                'valid' => $validation['valid'],
                'validationError' => $validation['error'],
            ];
        }

        usort(
            $stacks,
            static fn(array $a, array $b): int =>
                strcasecmp((string) $a['name'], (string) $b['name'])
        );

        return $stacks;
    }

    /**
     * Validate a Compose file with the Docker Compose plugin.
     *
     * @return array{valid:bool,error:?string}
     */
    public function validate(string $stackDirectory, string $composeFile): array
    {
        try {
            $directory = $this->assertInsideStacksDir($stackDirectory);
            $file = realpath($composeFile);

            if ($file === false || !is_file($file)) {
                return [
                    'valid' => false,
                    'error' => 'Compose file does not exist.',
                ];
            }

            $this->assertInsideStacksDir($file);

            $result = Command::run(
                $this->dockerBinary,
                ['compose', '-f', $file, 'config', '--quiet'],
                $directory,
                15
            );

            return [
                'valid' => $result['exitCode'] === 0,
                'error' => $result['exitCode'] === 0
                    ? null
                    : ($result['output'] ?: 'Compose validation failed.'),
            ];
        } catch (RuntimeException $exception) {
            return [
                'valid' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function findComposeFile(string $directory): ?string
    {
        foreach (self::FILENAMES as $filename) {
            $candidate = $directory . '/' . $filename;

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolve a path and ensure it remains inside STACKS_DIR.
     *
     * This is the foundation for later compose read/save/up/down operations.
     */
    private function assertInsideStacksDir(string $path): string
    {
        $root = realpath($this->stacksDir);
        $resolved = realpath($path);

        if ($root === false) {
            throw new RuntimeException('Stack root does not exist.');
        }

        if ($resolved === false) {
            throw new RuntimeException('Stack path does not exist.');
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($resolved !== $root && !str_starts_with($resolved, $rootPrefix)) {
            throw new RuntimeException('Stack path is outside the configured stack root.');
        }

        return $resolved;
    }
}
