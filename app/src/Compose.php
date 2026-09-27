<?php

declare(strict_types=1);

namespace DockerManger;

use DirectoryIterator;
use InvalidArgumentException;
use RuntimeException;

/** Compose discovery, file management and explicit lifecycle operations. */
final class Compose
{
    private const FILENAMES = ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'];
    private string $stacksDir;

    public function __construct(?string $stacksDir = null, private string $dockerBinary = 'docker')
    {
        $this->stacksDir = rtrim($stacksDir ?? (getenv('STACKS_DIR') ?: '/opt/stacks'), '/');
    }

    public function stacksDir(): string { return $this->stacksDir; }

    public function stacks(): array
    {
        if (!is_dir($this->stacksDir) || !is_readable($this->stacksDir)) return [];
        $stacks = [];
        foreach (new DirectoryIterator($this->stacksDir) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) continue;
            $file = $this->findComposeFile($entry->getPathname());
            if ($file === null) continue;
            $validation = $this->validate($entry->getPathname(), $file);
            $stacks[] = [
                'name' => $entry->getFilename(), 'path' => $entry->getPathname(),
                'file' => basename($file), 'composeFile' => $file,
                'valid' => $validation['valid'], 'validationError' => $validation['error'],
            ];
        }
        usort($stacks, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $stacks;
    }

    public function stack(string $name): ?array
    {
        $name = $this->assertStackName($name);
        $directory = $this->stacksDir . '/' . $name;
        if (!is_dir($directory)) return null;
        $directory = $this->assertInsideStacksDir($directory);
        $file = $this->findComposeFile($directory);
        if ($file === null) return null;
        $validation = $this->validate($directory, $file);
        return ['name'=>$name,'path'=>$directory,'file'=>basename($file),'composeFile'=>$file,'valid'=>$validation['valid'],'validationError'=>$validation['error']];
    }

    public function read(string $name): string
    {
        $stack = $this->requireStack($name);
        $contents = file_get_contents($stack['composeFile']);
        if ($contents === false) throw new RuntimeException('Unable to read Compose file.');
        return $contents;
    }

    public function save(string $name, string $contents): array
    {
        if (strlen($contents) > 1024 * 1024) throw new RuntimeException('Compose file is too large.');
        $stack = $this->requireStack($name);
        $tmp = $stack['path'] . '/.dockermanger-compose-' . bin2hex(random_bytes(6)) . '.yaml';
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) throw new RuntimeException('Unable to write temporary Compose file.');
        try {
            $validation = $this->validate($stack['path'], $tmp);
            if (!$validation['valid']) return ['ok'=>false,'error'=>$validation['error']];
            if (!rename($tmp, $stack['composeFile'])) throw new RuntimeException('Unable to replace Compose file.');
            return ['ok'=>true,'error'=>null];
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    public function create(string $name, string $contents): array
    {
        $name = $this->assertStackName($name);
        if (strlen($contents) > 1024 * 1024) throw new RuntimeException('Compose file is too large.');
        $root = realpath($this->stacksDir);
        if ($root === false || !is_writable($root)) throw new RuntimeException('Stack root is not writable.');
        $directory = $root . '/' . $name;
        if (file_exists($directory)) throw new RuntimeException('A stack with that name already exists.');
        if (!mkdir($directory, 0775, false)) throw new RuntimeException('Unable to create stack directory.');
        $file = $directory . '/compose.yaml';
        if (file_put_contents($file, $contents, LOCK_EX) === false) { @rmdir($directory); throw new RuntimeException('Unable to create Compose file.'); }
        $validation = $this->validate($directory, $file);
        if (!$validation['valid']) { @unlink($file); @rmdir($directory); return ['ok'=>false,'error'=>$validation['error']]; }
        return ['ok'=>true,'error'=>null,'name'=>$name];
    }

    public function up(string $name): array { return $this->runForStack($name, ['up','-d'], 180); }
    public function stop(string $name): array { return $this->runForStack($name, ['stop'], 120); }
    public function restart(string $name): array { return $this->runForStack($name, ['restart'], 180); }
    public function down(string $name): array { return $this->runForStack($name, ['down'], 180); }
    public function pull(string $name): array { return $this->runForStack($name, ['pull'], 600); }

    public function update(string $name): array
    {
        $pull = $this->pull($name);
        if ($pull['exitCode'] !== 0) return $pull;
        $up = $this->up($name);
        $up['output'] = trim("Pull:\n{$pull['output']}\n\nApply:\n{$up['output']}");
        return $up;
    }

    public function logs(string $name, int $tail = 250): array
    {
        $tail = max(10, min($tail, 2000));
        return $this->runForStack($name, ['logs','--no-color','--timestamps','--tail',(string)$tail], 30);
    }

    public function validate(string $stackDirectory, string $composeFile): array
    {
        try {
            $directory = $this->assertInsideStacksDir($stackDirectory);
            $file = realpath($composeFile);
            if ($file === false || !is_file($file)) return ['valid'=>false,'error'=>'Compose file does not exist.'];
            $this->assertInsideStacksDir($file);
            $result = Command::run($this->dockerBinary, ['compose','-f',$file,'config','--quiet'], $directory, 20);
            return ['valid'=>$result['exitCode']===0,'error'=>$result['exitCode']===0?null:($result['output'] ?: 'Compose validation failed.')];
        } catch (RuntimeException $e) { return ['valid'=>false,'error'=>$e->getMessage()]; }
    }

    private function runForStack(string $name, array $arguments, int $timeout): array
    {
        $stack = $this->requireStack($name);
        if (!$stack['valid']) return ['command'=>'','exitCode'=>2,'stdout'=>'','stderr'=>(string)$stack['validationError'],'output'=>(string)$stack['validationError']];
        return Command::run($this->dockerBinary, array_merge(['compose','-f',$stack['composeFile']], $arguments), $stack['path'], $timeout);
    }

    private function requireStack(string $name): array
    {
        $stack = $this->stack($name);
        if ($stack === null) throw new RuntimeException('Compose stack not found.');
        return $stack;
    }

    private function assertStackName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $name) || $name === '.' || $name === '..') {
            throw new InvalidArgumentException('Invalid stack name.');
        }
        return $name;
    }

    private function findComposeFile(string $directory): ?string
    {
        foreach (self::FILENAMES as $filename) if (is_file($directory.'/'.$filename)) return $directory.'/'.$filename;
        return null;
    }

    private function assertInsideStacksDir(string $path): string
    {
        $root = realpath($this->stacksDir); $resolved = realpath($path);
        if ($root === false) throw new RuntimeException('Stack root does not exist.');
        if ($resolved === false) throw new RuntimeException('Stack path does not exist.');
        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if ($resolved !== $root && !str_starts_with($resolved, $prefix)) throw new RuntimeException('Stack path is outside the configured stack root.');
        return $resolved;
    }
}
