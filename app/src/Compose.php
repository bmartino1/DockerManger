<?php

declare(strict_types=1);

namespace DockerManger;

use DirectoryIterator;
use InvalidArgumentException;
use RuntimeException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Compose discovery, file management and explicit lifecycle operations.
 *
 * Compose files remain the source of truth. Every stack path is constrained to
 * STACKS_DIR, and browser requests can invoke only the named operations below.
 */
final class Compose
{
    private const FILENAMES = ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'];
    private string $stacksDir;

    public function __construct(?string $stacksDir = null, private string $dockerBinary = 'docker')
    {
        $this->stacksDir = rtrim($stacksDir ?? (getenv('STACKS_DIR') ?: '/opt/stacks'), '/');
    }

    public function stacksDir(): string { return $this->stacksDir; }

    /** Report the mount state used by dashboard diagnostics and error messages. */
    public function storageStatus(): array
    {
        $exists = is_dir($this->stacksDir);
        return [
            'path' => $this->stacksDir,
            'exists' => $exists,
            'readable' => $exists && is_readable($this->stacksDir),
            'writable' => $exists && is_writable($this->stacksDir),
        ];
    }

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
        if ($contents === false) throw new RuntimeException('DockerManger cannot read the Compose file at ' . $stack['composeFile'] . '. Check the host bind-mount permissions.');
        return $contents;
    }


    /** Read the conventional per-stack .env file. Missing files are treated as empty. */
    public function readEnv(string $name): string
    {
        $stack = $this->requireStack($name);
        $file = $stack['path'] . '/.env';
        if (!is_file($file)) return '';
        $contents = file_get_contents($file);
        if ($contents === false) throw new RuntimeException('DockerManger cannot read the stack .env file.');
        return $contents;
    }

    /** Save only the conventional .env file inside the validated stack directory. */
    public function saveEnv(string $name, string $contents): array
    {
        if (strlen($contents) > 256 * 1024) throw new RuntimeException('Environment file is too large.');
        $stack = $this->requireStack($name);
        if (!is_writable($stack['path'])) throw new RuntimeException('DockerManger cannot edit this stack directory. Check the host bind-mount permissions.');
        $file = $stack['path'] . '/.env';
        if (file_put_contents($file, $contents, LOCK_EX) === false) throw new RuntimeException('DockerManger could not write the stack .env file.');
        return ['ok'=>true,'error'=>null];
    }

    /** Validate a temporary Compose file before replacing the live file. */
    public function save(string $name, string $contents): array
    {
        if (strlen($contents) > 1024 * 1024) throw new RuntimeException('Compose file is too large.');
        $stack = $this->requireStack($name);
        $composeName = $this->explicitComposeName($contents);
        if ($composeName !== null && $composeName !== $stack['name']) {
            throw new RuntimeException('The top-level Compose name is "' . $composeName . '", but this stack is "' . $stack['name'] . '". Create/rename the stack with the matching project name instead of changing stack identity in place.');
        }
        $tmp = $stack['path'] . '/.dockermanger-compose-' . bin2hex(random_bytes(6)) . '.yaml';
        if (!is_writable($stack['path'])) throw new RuntimeException('DockerManger cannot edit this stack because ' . $stack['path'] . ' is not writable. Check the host directory mounted to /opt/stacks.');
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) throw new RuntimeException('DockerManger could not create a temporary validation file in ' . $stack['path'] . '. Check the host bind-mount permissions.');
        try {
            $validation = $this->validate($stack['path'], $tmp);
            if (!$validation['valid']) return ['ok'=>false,'error'=>$validation['error']];
            if (!rename($tmp, $stack['composeFile'])) throw new RuntimeException('Compose validation succeeded, but DockerManger could not replace ' . $stack['composeFile'] . '. Check file ownership and directory permissions.');
            return ['ok'=>true,'error'=>null];
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    /**
     * Create a new managed stack only after its Compose file validates.
     *
     * A top-level Compose `name:` is the authoritative project name when one
     * is present. Otherwise the name entered in DockerManger is used.
     * `container_name:` never changes stack identity.
     */
    public function create(string $name, string $contents, string $envContents = ''): array
    {
        $requestedName = $this->assertStackName($name);
        $composeName = $this->explicitComposeName($contents);
        $name = $composeName ?? $requestedName;
        if (strlen($contents) > 1024 * 1024) throw new RuntimeException('Compose file is too large.');
        if (strlen($envContents) > 256 * 1024) throw new RuntimeException('Environment file is too large.');
        $root = realpath($this->stacksDir);
        if ($root === false) throw new RuntimeException('The configured stack root does not exist: ' . $this->stacksDir);
        if (!is_writable($root)) throw new RuntimeException('DockerManger cannot create stacks because ' . $root . ' is not writable. Check the host directory mounted to /opt/stacks.');
        $directory = $root . '/' . $name;
        if (file_exists($directory)) throw new RuntimeException('A stack with that name already exists.');
        if (!mkdir($directory, 0775, false)) throw new RuntimeException('DockerManger could not create the stack directory ' . $directory . '. Check the stack-root permissions.');
        $file = $directory . '/compose.yaml';
        if (file_put_contents($file, $contents, LOCK_EX) === false) { @rmdir($directory); throw new RuntimeException('DockerManger created the stack directory but could not write ' . $file . '. Check directory ownership and permissions.'); }
        // Create .env before validation so Compose expressions such as
        // ${PORT:?required} can be validated using values entered on this page.
        $envFile = $directory . '/.env';
        if (file_put_contents($envFile, $envContents, LOCK_EX) === false) {
            @unlink($file); @rmdir($directory);
            throw new RuntimeException('DockerManger could not create the stack .env file.');
        }
        $validation = $this->validate($directory, $file);
        if (!$validation['valid']) { @unlink($envFile); @unlink($file); @rmdir($directory); return ['ok'=>false,'error'=>$validation['error']]; }
        return ['ok'=>true,'error'=>null,'name'=>$name,'requestedName'=>$requestedName,'nameSource'=>$composeName !== null ? 'compose' : 'form'];
    }

    public function up(string $name): array { return $this->runForStack($name, ['up','-d'], 180); }
    public function stop(string $name): array { return $this->runForStack($name, ['stop'], 120); }
    public function restart(string $name): array { return $this->runForStack($name, ['restart'], 180); }
    public function down(string $name): array { return $this->runForStack($name, ['down'], 180); }


    /** Down the stack, then remove only its directory beneath STACKS_DIR. */
    public function delete(string $name): array
    {
        $stack = $this->requireStack($name);
        $down = $this->down($name);
        if ($down['exitCode'] !== 0) return $down;

        $directory = $this->assertInsideStacksDir($stack['path']);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isLink() || $item->isFile()) {
                if (!@unlink($path)) throw new RuntimeException('Unable to delete stack file: ' . $path);
            } elseif (!@rmdir($path)) {
                throw new RuntimeException('Unable to delete stack directory: ' . $path);
            }
        }
        if (!@rmdir($directory)) throw new RuntimeException('Unable to remove stack directory: ' . $directory);
        return ['command'=>$down['command'],'exitCode'=>0,'stdout'=>$down['stdout'],'stderr'=>'','output'=>trim($down['output'] . "\nStack files deleted.")];
    }
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
        return Command::run($this->dockerBinary, array_merge(['compose','-p',$stack['name'],'-f',$stack['composeFile']], $arguments), $stack['path'], $timeout);
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

    /** Return a conventional top-level Compose name, if explicitly set. */
    private function explicitComposeName(string $contents): ?string
    {
        // Stack names are intentionally restricted to the same conservative
        // character set DockerManger accepts for directory names. Matching at
        // column zero avoids confusing service-level properties with project
        // identity. Quoted and unquoted scalar names are supported.
        if (!preg_match('/^name\s*:\s*(["\']?)([A-Za-z0-9][A-Za-z0-9_.-]{0,63})\1\s*(?:#.*)?$/m', $contents, $match)) {
            return null;
        }
        return $this->assertStackName($match[2]);
    }

    private function findComposeFile(string $directory): ?string
    {
        foreach (self::FILENAMES as $filename) if (is_file($directory.'/'.$filename)) return $directory.'/'.$filename;
        return null;
    }

    /** Prevent symlinks/path tricks from escaping the configured stack root. */
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
