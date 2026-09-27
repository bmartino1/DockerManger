<?php

declare(strict_types=1);

/**
 * Browser console page.
 *
 * PHP chooses/validates the visible target. Interactive bytes never pass
 * through a PHP command endpoint; xterm.js connects to the loopback-only
 * Node/node-pty helper through Nginx's same-origin /terminal-ws proxy.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';

use DockerManger\Docker;

$dockerClient = new Docker();
$containerName = trim((string) ($_GET['container'] ?? ''));
$requestedTarget = trim((string) ($_GET['target'] ?? ''));
$container = $containerName !== '' ? $dockerClient->container($containerName) : null;
$envBool = static function (string $name, bool $default = false): bool {
    $raw = getenv($name);
    if ($raw === false || trim((string) $raw) === '') return $default;
    return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
};
$hostEnabled = $envBool('DOCKERMANGER_HOST_SHELL_ENABLED', false);
$consoleEnabled = $envBool('DOCKERMANGER_ENABLE_CONSOLE', true);
$hostSshHost = (string) (getenv('DOCKERMANGER_HOST_SSH_HOST') ?: 'host.docker.internal');
$hostSshPort = (string) (getenv('DOCKERMANGER_HOST_SSH_PORT') ?: '22');
$hostSshUser = (string) (getenv('DOCKERMANGER_HOST_SSH_USER') ?: 'root');
$hostSshKey = trim((string) (getenv('DOCKERMANGER_HOST_SSH_KEY') ?: ''));

if ($containerName !== '' && $container === null) {
    http_response_code(404);
}

$target = $container !== null ? 'container' : ($requestedTarget !== '' ? $requestedTarget : 'local');
if (!in_array($target, ['local', 'container', 'host'], true) || ($target === 'host' && !$hostEnabled)) {
    $target = 'local';
}

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$back = '/';
if ($container !== null) {
    $project = Docker::composeProject($container);
    $back = $project !== null ? '/?stack=' . urlencode($project) : '/?container=' . urlencode((string) $container['name']);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="stylesheet" href="/css/app.css">
    <link rel="stylesheet" href="/vendor/xterm/xterm.css">
    <title>Console · DockerManger</title>
</head>
<body>
<div class="console-page">
    <header class="topbar">
        <a class="brand" href="/"><span class="logo">DM</span><span><strong>DockerManger</strong><small>Interactive console</small></span></a>
        <a class="button button-secondary" href="<?= $escape($back) ?>">← Back</a>
    </header>
    <main class="content">
        <section class="page-heading console-heading">
            <div>
                <span class="eyebrow">Console</span>
                <h1><?= $container ? $escape($container['name']) : ($target === 'host' ? 'Host Console (SSH)' : 'DockerManger Console') ?></h1>
                <p>The terminal transport accepts controlled targets only; it is not a generic HTTP command API.</p>
            </div>
            <?php if ($container === null): ?>
                <div class="console-target-switch" aria-label="Console target">
                    <a class="button <?= $target === 'local' ? '' : 'button-secondary' ?>" href="/console.php?target=local">DockerManger Console</a>
                    <?php if ($hostEnabled): ?>
                        <a class="button <?= $target === 'host' ? '' : 'button-secondary' ?>" href="/console.php?target=host">Host Console (SSH)</a>
                    <?php else: ?>
                        <span class="button button-secondary disabled-button" title="Enable DOCKERMANGER_HOST_SHELL_ENABLED to use the host SSH console.">Host Console disabled</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php if ($container === null && $target === 'host'): ?>
            <div class="notice notice-warning"><strong>SSH-backed host session</strong><span>Connecting as <?= $escape($hostSshUser) ?>@<?= $escape($hostSshHost) ?>:<?= $escape($hostSshPort) ?>. Commands run with that host account's privileges.</span></div>
        <?php endif; ?>

        <?php if ($container === null): ?>
            <section class="panel host-console-summary">
                <div class="panel-heading"><div><span class="eyebrow">Host SSH</span><h2>Host Console Configuration</h2></div><span class="validity <?= $hostEnabled ? 'good' : 'bad' ?>"><?= $hostEnabled ? 'Enabled' : 'Disabled' ?></span></div>
                <div class="info-grid">
                    <div><span>Target</span><strong><?= $escape($hostSshHost) ?></strong></div>
                    <div><span>User</span><strong><?= $escape($hostSshUser) ?></strong></div>
                    <div><span>Port</span><strong><?= $escape($hostSshPort) ?></strong></div>
                    <div><span>Identity</span><strong><?= $hostSshKey !== '' ? $escape($hostSshKey) : 'Default SSH key / interactive password' ?></strong></div>
                </div>
                <?php if (!$hostEnabled): ?>
                    <div class="notice notice-warning embedded-notice"><strong>Host SSH is disabled in this running container.</strong><span>Set <code>DOCKERMANGER_HOST_SHELL_ENABLED=true</code> in <code>dockerenvironment.env</code>, then recreate DockerManger with <code>docker compose up -d</code>. A clean-clone rebuild restores the repository default unless you edit the deployment env again.</span></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!$consoleEnabled): ?>
            <div class="notice notice-error"><strong>Console is disabled.</strong><span>Set DOCKERMANGER_ENABLE_CONSOLE=true and recreate DockerManger to enable it.</span></div>
        <?php elseif ($containerName !== '' && $container === null): ?>
            <div class="notice notice-error"><strong>Container not found.</strong><span>The requested console target no longer exists.</span></div>
        <?php else: ?>
            <section class="panel terminal-panel">
                <div class="panel-heading">
                    <div><span class="eyebrow">PTY</span><h2 id="terminal-title">Connecting…</h2></div>
                    <?php if ($container === null): ?><span class="count-badge"><?= $target === 'host' ? 'Host SSH' : 'DockerManger' ?></span><?php endif; ?>
                </div>
                <div id="terminal" class="terminal-surface" data-target="<?= $escape($target) ?>" data-container="<?= $escape($container['name'] ?? '') ?>"></div>
                <div id="terminal-status" class="terminal-status">Opening secure WebSocket terminal…</div>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php if ($consoleEnabled && !($containerName !== '' && $container === null)): ?>
<script src="/vendor/xterm/xterm.js"></script>
<script src="/js/console.js"></script>
<?php endif; ?>
</body>
</html>
