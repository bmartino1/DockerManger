<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
use DockerManger\Auth;
if (Auth::configured()) { header('Location: /login.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_csrf();
        Auth::setup(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''));
        header('Location: /login.php?created=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$title='First-time setup'; $action='/setup.php'; $button='Create administrator';
require dirname(__DIR__) . '/templates/auth.php';
