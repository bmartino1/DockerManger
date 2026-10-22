<?php

declare(strict_types=1);

namespace DockerManger;

final class Auth
{
    private const FILE = '/data/auth/admin.json';

    public static function configured(): bool { return is_file(self::FILE); }
    public static function loggedIn(): bool { return self::configured() && !empty($_SESSION['dm_admin']); }

    public static function requirePage(): void
    {
        if (!self::configured()) { header('Location: /setup.php', true, 302); exit; }
        if (!self::loggedIn()) { header('Location: /login.php', true, 302); exit; }
    }

    public static function requireApi(): void
    {
        if (self::loggedIn()) return;
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
        exit;
    }

    public static function setup(string $username, string $password): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{2,31}$/', $username)) throw new \RuntimeException('Username must be 3–32 letters, numbers, _ or -, starting with a letter.');
        if (strlen($password) < 12 || strlen($password) > 1024) throw new \RuntimeException('Password must be 12–1024 characters.');
        $dir = dirname(self::FILE);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('Cannot create /data/auth; check permissions.');
        $json = json_encode(['username'=>$username, 'hash'=>password_hash($password, PASSWORD_DEFAULT)], JSON_THROW_ON_ERROR);
        $handle = @fopen(self::FILE, 'x');
        if (!$handle) throw new \RuntimeException('Setup already completed or /data/auth is not writable.');
        chmod(self::FILE, 0600);
        try {
            if (fwrite($handle, $json) !== strlen($json)) throw new \RuntimeException('Unable to write account.');
            fflush($handle);
        } finally { fclose($handle); }
    }

    public static function login(string $username, string $password): bool
    {
        $account = json_decode((string)@file_get_contents(self::FILE), true);
        if (!is_array($account) || !isset($account['username'], $account['hash'])) return false;
        if (!hash_equals((string)$account['username'], $username) || !password_verify($password, (string)$account['hash'])) return false;
        session_regenerate_id(true);
        $_SESSION['dm_admin'] = true;
        return true;
    }
}
