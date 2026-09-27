<?php

declare(strict_types=1);

/**
 * Main DockerManger dashboard.
 *
 * Variables are prepared by app/public/index.php. Keep command execution and
 * Docker/Compose interpretation out of this template.
 */

$escape = static fn(mixed $value): string =>
    htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$stateLabel = static fn(string $state): string => match ($state) {
    'active' => 'Active',
    'exited' => 'Exited',
    'degraded' => 'Degraded',
    default => 'Inactive',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>DockerManger</title>

    <link rel="stylesheet" href="/css/app.css">
</head>

<body>
<div class="app-shell">

    <header class="topbar">
        <a class="brand" href="/" aria-label="DockerManger dashboard">
            <span class="logo" aria-hidden="true">DM</span>
            <span>
                <strong>DockerManger</strong>
                <small>Compose &amp; container management</small>
            </span>
        </a>

        <div class="top-actions">
            <span class="engine-status <?= !empty($docker['available']) ? 'online' : 'offline' ?>">
                <span class="status-dot" aria-hidden="true"></span>
                Docker <?= !empty($docker['available']) ? 'Connected' : 'Unavailable' ?>
            </span>

            <button class="button button-secondary" type="button" id="refresh-dashboard">
                Refresh
            </button>
        </div>
    </header>

    <div class="layout">

        <aside class="sidebar">
            <div class="sidebar-head">
                <div>
                    <span class="eyebrow">Compose</span>
                    <h2>Stacks</h2>
                </div>

                <span class="count-badge"><?= count($stacks) ?></span>
            </div>

            <label class="search-box">
                <span class="sr-only">Search stacks</span>
                <input
                    id="stack-search"
                    type="search"
                    placeholder="Search stacks..."
                    autocomplete="off"
                >
            </label>

            <nav class="stack-list" id="stack-list" aria-label="Compose stacks">
                <?php if ($stacks === []): ?>
                    <div class="empty compact">
                        <strong>No Compose stacks found</strong>
                        <span><?= $escape($system['stacksDir']) ?></span>
                    </div>
                <?php else: ?>
                    <?php foreach ($stacks as $stack): ?>
                        <article
                            class="stack-item"
                            data-stack-name="<?= $escape(strtolower((string) $stack['name'])) ?>"
                        >
                            <div class="stack-title">
                                <span class="status <?= $escape((string) $stack['state']) ?>"></span>
                                <strong><?= $escape($stack['name']) ?></strong>
                            </div>

                            <span class="stack-state">
                                <?= $escape($stateLabel((string) $stack['state'])) ?>
                            </span>

                            <small>
                                <?= (int) $stack['running'] ?> running /
                                <?= (int) $stack['stopped'] ?> stopped
                            </small>

                            <?php if (empty($stack['valid'])): ?>
                                <small class="error-text" title="<?= $escape($stack['validationError'] ?? '') ?>">
                                    Compose validation failed
                                </small>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </nav>
        </aside>

        <main class="content">

            <section class="page-heading">
                <div>
                    <span class="eyebrow">Overview</span>
                    <h1>Docker Dashboard</h1>
                    <p>
                        <?= $escape($system['hostname']) ?>
                        · <?= $escape($system['architecture']) ?>
                        · stacks at <?= $escape($system['stacksDir']) ?>
                    </p>
                </div>
            </section>

            <section class="stats" aria-label="DockerManger summary">
                <article class="stat-card">
                    <span>Running</span>
                    <strong><?= $running ?></strong>
                    <small>containers</small>
                </article>

                <article class="stat-card">
                    <span>Stopped</span>
                    <strong><?= $stopped ?></strong>
                    <small>containers</small>
                </article>

                <article class="stat-card">
                    <span>Stacks</span>
                    <strong><?= count($stacks) ?></strong>
                    <small><?= $stackCounts['active'] ?> active</small>
                </article>

                <article class="stat-card <?= $invalidStacks > 0 ? 'warning' : '' ?>">
                    <span>Compose</span>
                    <strong><?= $invalidStacks ?></strong>
                    <small><?= $invalidStacks === 1 ? 'invalid stack' : 'invalid stacks' ?></small>
                </article>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <span class="eyebrow">Runtime</span>
                        <h2>Docker Host</h2>
                    </div>
                </div>

                <div class="info-grid">
                    <div>
                        <span>Engine</span>
                        <strong><?= !empty($docker['available']) ? 'Connected' : 'Unavailable' ?></strong>
                    </div>

                    <div>
                        <span>Docker Client</span>
                        <strong><?= $escape($docker['clientVersion'] ?? 'Unavailable') ?></strong>
                    </div>

                    <div>
                        <span>Docker Server</span>
                        <strong><?= $escape($docker['serverVersion'] ?? 'Unavailable') ?></strong>
                    </div>

                    <div>
                        <span>PHP</span>
                        <strong><?= $escape($system['phpVersion']) ?></strong>
                    </div>

                    <div>
                        <span>Kernel</span>
                        <strong><?= $escape($system['kernel']) ?></strong>
                    </div>

                    <div>
                        <span>Timezone</span>
                        <strong><?= $escape($system['timezone']) ?></strong>
                    </div>
                </div>

                <?php if (empty($docker['available'])): ?>
                    <div class="notice notice-error">
                        <strong>Docker Engine is unavailable.</strong>
                        <span><?= $escape($docker['error'] ?? 'Check the Docker socket and host engine.') ?></span>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <span class="eyebrow">Containers</span>
                        <h2>All Containers</h2>
                    </div>

                    <span class="count-badge"><?= count($containers) ?></span>
                </div>

                <?php if ($containers === []): ?>
                    <div class="empty">
                        <strong>No containers to display</strong>
                        <span>
                            <?= !empty($docker['available'])
                                ? 'Docker is connected, but no containers were returned.'
                                : 'Container data will appear when Docker is available.' ?>
                        </span>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Status</th>
                                <th>Name</th>
                                <th>Image</th>
                                <th>Compose Project</th>
                                <th>Ports</th>
                            </tr>
                            </thead>

                            <tbody>
                            <?php foreach ($containers as $container): ?>
                                <?php $project = \DockerManger\Docker::composeProject($container); ?>
                                <tr>
                                    <td>
                                        <span class="container-state <?= $escape($container['state']) ?>">
                                            <span class="status-dot"></span>
                                            <?= $escape($container['status']) ?>
                                        </span>
                                    </td>

                                    <td class="primary-cell">
                                        <?= $escape($container['name']) ?>
                                    </td>

                                    <td><?= $escape($container['image']) ?></td>

                                    <td><?= $escape($project ?? '—') ?></td>

                                    <td class="muted-cell">
                                        <?= $escape($container['ports'] ?: '—') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

        </main>
    </div>
</div>

<script src="/js/app.js"></script>
</body>
</html>
