<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
use DockerManger\Auth;
if (!Auth::configured()) { header('Location: /setup.php'); exit; }
if (Auth::loggedIn()) { header('Location: /'); exit; }
$error='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_csrf();
        if (!Auth::login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
            usleep(350000);
            throw new RuntimeException('Invalid username or password.');
        }
        header('Location: /'); exit;
    } catch (Throwable $e) { $error=$e->getMessage(); }
}
$title='Sign in'; $action='/login.php'; $button='Sign in';
require dirname(__DIR__) . '/templates/auth.php';
