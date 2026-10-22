<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title, ENT_QUOTES) ?> · DockerManger</title>
<link rel="stylesheet" href="/css/app.css">
<style>body{min-height:100vh;display:grid;place-items:center;background:#0c1220;color:#e8edf6;font-family:system-ui,sans-serif}.auth-card{width:min(420px,92vw);padding:32px;background:#182237;border:1px solid #34415b;border-radius:16px}.auth-card input{display:block;width:100%;box-sizing:border-box;padding:12px;margin:7px 0 18px;border-radius:8px;border:1px solid #65748c;background:#0c1220;color:white}.auth-card button{padding:12px 20px;border:0;border-radius:8px;background:#3b82f6;color:white;cursor:pointer}.auth-error{color:#ffb4b4}</style>
</head><body><main class="auth-card"><h1>DockerManger</h1><h2><?= htmlspecialchars($title, ENT_QUOTES) ?></h2>
<?php if ($action === '/setup.php'): ?><p>Create your administrator account. There is no default password.</p><?php endif; ?>
<?php if ($error !== ''): ?><p class="auth-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES) ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
<label for="username">Username</label><input id="username" name="username" autocomplete="username" required maxlength="32" value="<?= $action === '/setup.php' ? 'admin' : '' ?>">
<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="<?= $action === '/setup.php' ? 'new-password' : 'current-password' ?>" required <?= $action === '/setup.php' ? 'minlength="' . \DockerManger\Auth::minimumPasswordLength() . '"' : '' ?>>
<button type="submit"><?= htmlspecialchars($button, ENT_QUOTES) ?></button></form></main></body></html>
