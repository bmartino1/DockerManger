<?php

declare(strict_types=1);

/**
 * Container detail page for externally-managed/standalone containers.
 *
 * There is intentionally no Compose editor here. A container may have been
 * created by docker run, another management tool, or a Compose project outside
 * DockerManger's STACKS_DIR, so DockerManger must not claim ownership of its
 * configuration.
 */

$escape = static fn(mixed $value): string =>
    htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$managedProjects = array_column($stacks, 'name');
$project = $selectedContainer ? \DockerManger\Docker::composeProject($selectedContainer) : null;
$isManagedCompose = $project !== null && in_array($project, $managedProjects, true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="stylesheet" href="/css/app.css">
    <title><?= $selectedContainer ? $escape($selectedContainer['name']) . ' · ' : '' ?>DockerManger</title>
</head>
<body>
<div class="app-shell">
<header class="topbar">
    <a class="brand" href="/"><span class="logo">DM</span><span><strong>DockerManger</strong><small>Compose &amp; container management</small></span></a>
    <div class="top-actions"><a class="button button-secondary" href="/">← Dashboard</a></div>
</header>
<div class="layout">
<aside class="sidebar">
    <div class="sidebar-head"><div><span class="eyebrow">Compose</span><h2>Stacks</h2></div><a class="button button-small" href="/#new-stack">+ Compose</a></div>
    <label class="search-box"><span class="sr-only">Search stacks</span><input id="stack-search" type="search" placeholder="Search stacks..."></label>
    <nav class="stack-list">
        <?php foreach ($stacks as $stack): ?>
            <a class="stack-item stack-link" data-stack-name="<?= $escape(strtolower($stack['name'])) ?>" href="/?stack=<?= urlencode($stack['name']) ?>">
                <div class="stack-title"><span class="status <?= $escape($stack['state']) ?>"></span><strong><?= $escape($stack['name']) ?></strong></div>
                <small><?= (int) $stack['running'] ?> running / <?= (int) $stack['stopped'] ?> stopped</small>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
<main class="content">
<?php if (isset($pageError)): ?>
    <section class="notice notice-error"><strong><?= $escape($pageError) ?></strong></section>
<?php else: ?>
    <section class="page-heading stack-heading">
        <div>
            <span class="eyebrow"><?= $isManagedCompose ? 'Compose Container' : 'External Container' ?></span>
            <h1><span class="status <?= !empty($selectedContainer['running']) ? 'active' : 'exited' ?>"></span><?= $escape($selectedContainer['name']) ?></h1>
            <p><?= $escape($selectedContainer['image']) ?> · <?= $escape($selectedContainer['status']) ?></p>
        </div>
    </section>

    <?php if ($isManagedCompose): ?>
        <div class="notice"><strong>This container belongs to the managed Compose stack “<?= $escape($project) ?>”.</strong><span>Use the stack page for Compose lifecycle and configuration changes. <a href="/?stack=<?= urlencode($project) ?>">Open stack</a></span></div>
    <?php else: ?>
        <div class="notice"><strong>Externally managed container</strong><span>DockerManger can control its runtime state, logs and console, but does not own the configuration that created it.</span></div>
    <?php endif; ?>

    <section class="stack-actions" data-container="<?= $escape($selectedContainer['id']) ?>" data-csrf="<?= $escape(csrf_token()) ?>">
        <button class="button container-action" data-action="container-start">Start</button>
        <button class="button button-secondary container-action" data-action="container-stop">Stop</button>
        <button class="button button-secondary container-action" data-action="container-restart">Restart</button>
        <a class="button button-secondary" href="/console.php?container=<?= urlencode($selectedContainer['name']) ?>">Console</a>
    </section>
    <div id="action-result" class="operation-output" hidden></div>

    <div class="stack-grid">
        <section class="panel">
            <div class="panel-heading"><div><span class="eyebrow">Runtime</span><h2>Container Details</h2></div></div>
            <div class="detail-list">
                <div><span>Name</span><strong><?= $escape($selectedContainer['name']) ?></strong></div>
                <div><span>Image</span><strong><?= $escape($selectedContainer['image']) ?></strong></div>
                <div><span>Container ID</span><code><?= $escape(substr($selectedContainer['id'], 0, 20)) ?>…</code></div>
                <div><span>Status</span><strong><?= $escape($selectedContainer['status']) ?></strong></div>
                <div><span>Ports</span><code><?= $escape($selectedContainer['ports'] ?: 'No published ports') ?></code></div>
                <div><span>Restart policy</span><strong><?= $escape($containerDetails['restartPolicy'] ?? 'unknown') ?></strong></div>
                <div><span>Privileged</span><strong><?= !empty($containerDetails['privileged']) ? 'Yes' : 'No' ?></strong></div>
                <div><span>Read-only rootfs</span><strong><?= !empty($containerDetails['readOnlyRootfs']) ? 'Yes' : 'No' ?></strong></div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-heading"><div><span class="eyebrow">Docker</span><h2>Networks &amp; Mounts</h2></div></div>
            <div class="detail-section"><h3>Networks</h3>
                <?php if (empty($containerDetails['networks'])): ?><p class="muted-cell">No network details returned.</p><?php else: ?>
                    <?php foreach ($containerDetails['networks'] as $network): ?><div class="detail-row"><strong><?= $escape($network['name']) ?></strong><code><?= $escape($network['ipAddress'] ?: 'no IP') ?></code></div><?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="detail-section"><h3>Mounts</h3>
                <?php if (empty($containerDetails['mounts'])): ?><p class="muted-cell">No mounts.</p><?php else: ?>
                    <?php foreach ($containerDetails['mounts'] as $mount): ?><div class="mount-row"><span><?= $escape($mount['type']) ?> · <?= !empty($mount['rw']) ? 'rw' : 'ro' ?></span><code><?= $escape($mount['source']) ?> → <?= $escape($mount['destination']) ?></code></div><?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-heading"><div><span class="eyebrow">Logs</span><h2>Container Logs</h2></div><button id="refresh-container-logs" class="button button-secondary button-small" data-container="<?= $escape($selectedContainer['id']) ?>">Refresh Logs</button></div>
        <pre id="container-logs" class="log-viewer"><?= $escape($containerLogs['output'] ?? '') ?></pre>
    </section>
<?php endif; ?>
</main>
</div>
</div>
<script src="/js/app.js"></script>
</body>
</html>
