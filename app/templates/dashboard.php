<?php
declare(strict_types=1);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DockerManger</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<header class="topbar">
    <div class="brand"><span class="logo">D</span> DockerManger</div>
    <div class="top-actions">
        <span><?= htmlspecialchars(SystemInfo::hostname()) ?></span>
        <span class="readonly">READ ONLY</span>
    </div>
</header>

<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-head">
            <button class="compose-button" disabled>＋ Compose</button>
            <input id="stack-search" type="search" placeholder="Search stacks">
        </div>

        <nav id="stack-list">
            <?php foreach ($stacks as $stack): ?>
                <div class="stack-item"
                     data-name="<?= htmlspecialchars(strtolower($stack['name'])) ?>">
                    <span class="status <?= $stack['valid'] ? 'good' : 'bad' ?>"></span>
                    <span><?= htmlspecialchars($stack['name']) ?></span>
                </div>
            <?php endforeach; ?>

            <?php if (!$stacks): ?>
                <div class="empty">No Compose stacks found in /opt/stacks.</div>
            <?php endif; ?>
        </nav>
    </aside>

    <main>
        <h1>Home</h1>

        <section class="stats">
            <article>
                <span>running</span>
                <strong><?= count($running) ?></strong>
            </article>
            <article>
                <span>stopped</span>
                <strong><?= $stopped ?></strong>
            </article>
            <article>
                <span>stacks</span>
                <strong><?= count($stacks) ?></strong>
            </article>
            <article>
                <span>errors</span>
                <strong><?= $invalidStacks ?></strong>
            </article>
        </section>

        <section class="panel">
            <h2>Docker Host</h2>
            <div class="info-grid">
                <div><small>Connection</small><b><?= $docker->available() ? 'Connected' : 'Unavailable' ?></b></div>
                <div><small>Docker Version</small><b><?= htmlspecialchars($docker->version()) ?></b></div>
                <div><small>Hostname</small><b><?= htmlspecialchars(SystemInfo::hostname()) ?></b></div>
                <div><small>Mode</small><b>Read-only UI</b></div>
            </div>
        </section>

        <section class="panel">
            <h2>Containers</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Name</th><th>Image</th><th>State</th><th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($containers as $container): ?>
                        <tr>
                            <td><?= htmlspecialchars($container['Names'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($container['Image'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($container['State'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($container['Status'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$containers): ?>
                        <tr><td colspan="4">No containers returned by Docker.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<script src="/js/app.js"></script>
</body>
</html>
