<?php

declare(strict_types=1);

/**
 * Managed Compose stack detail page.
 *
 * Docker/Compose state is prepared by index.php. This template only renders
 * that state and supplies identifiers/CSRF tokens to the narrow API actions.
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
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="stylesheet" href="/css/app.css"><link rel="stylesheet" href="/css/modern.css">
    <title><?= isset($selected) ? $escape($selected['name']) . ' · ' : '' ?>DockerManger</title>
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <a class="brand" href="/">
            <span class="logo">DM</span>
            <span><strong>DockerManger</strong><small>Compose &amp; container management</small></span>
        </a>
        <div class="top-actions"><a class="button button-secondary" href="/console.php?target=local">Console</a><form method="post" action="/logout.php" style="margin-left:auto"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>"><button class="button button-secondary" type="submit">Sign out</button></form></div></header>

    <div class="layout">
        <aside class="sidebar"><nav class="dm-primary-nav" aria-label="Main navigation"><a href="/">⌂ Dashboard</a><a href="/?view=apps">▦ Apps</a><a href="/?view=apps#stacks">▦ Stacks</a><a href="/?view=images">▤ Images</a><a href="/?view=volumes">▤ Volumes</a><a href="/?view=apps#resources">⌁ Resources</a></nav>
            <div class="sidebar-head">
                <div><span class="eyebrow">Compose</span><h2>Stacks</h2></div>
                <a class="button button-secondary button-small sidebar-compose-button" href="/create.php">+ Compose</a>
            </div>
            <label class="search-box">
                <span class="sr-only">Search stacks</span>
                <input id="stack-search" type="search" placeholder="Search stacks...">
            </label>
            <nav class="stack-list">
                <?php foreach ($stacks as $stack): ?>
                    <a class="stack-item stack-link <?= isset($selected) && $stack['name'] === $selected['name'] ? 'selected' : '' ?>"
                       data-stack-name="<?= $escape(strtolower($stack['name'])) ?>"
                       href="/?stack=<?= urlencode($stack['name']) ?>">
                        <div class="stack-title">
                            <span class="status <?= $escape($stack['state']) ?>"></span>
                            <strong><?= $escape($stack['name']) ?></strong>
                        </div>
                        <span class="stack-state"><?= $escape($stateLabel($stack['state'])) ?></span>
                        <small><?= (int) $stack['running'] ?> running / <?= (int) $stack['stopped'] ?> stopped</small>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php if ($thirdPartyContainers !== []): ?>
                <div class="sidebar-section"><span class="eyebrow">External</span><h3>Containers</h3>
                    <?php foreach ($thirdPartyContainers as $external): ?>
                        <a class="external-item" href="/?container=<?= urlencode($external['name']) ?>"><span class="status <?= !empty($external['running']) ? 'active' : 'exited' ?>"></span><span><?= $escape($external['name']) ?></span><small>3rd party</small></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </aside>

        <main class="content">
            <?php if (isset($pageError)): ?>
                <section class="notice notice-error"><strong><?= $escape($pageError) ?></strong></section>
            <?php else: ?>
                <section class="page-heading stack-heading">
                    <div>
                        <span class="eyebrow">Compose Stack</span>
                        <h1><span class="status <?= $escape($selected['state']) ?>"></span><?= $escape($selected['name']) ?></h1>
                        <p><?= $escape($selected['composeFile']) ?> · <?= $escape($selected['state']) ?> · <?= (int) $selected['containerCount'] ?> containers</p>
                    </div>
                </section>

                <section class="stack-actions" data-stack="<?= $escape($selected['name']) ?>" data-csrf="<?= $escape(csrf_token()) ?>">
                    <button class="button stack-action" data-action="stack-start">Start</button>
                    <button class="button button-secondary stack-action" data-action="stack-stop">Stop</button>
                    <button class="button button-secondary stack-action" data-action="stack-restart">Restart</button>
                    <button class="button stack-action" data-action="stack-update">Update</button>

                    <button class="button button-danger stack-action" data-action="stack-down">Down</button>
                    <button class="button button-danger stack-action" data-action="stack-delete">Down &amp; Delete</button>
                </section>
                <div id="action-result" class="operation-output" hidden></div>

                <?php if (empty($selected['valid'])): ?>
                    <div class="notice notice-error">
                        <strong>Compose validation failed.</strong>
                        <span><?= $escape($selected['validationError']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (empty($storage['writable'])): ?>
                    <div class="notice notice-error storage-warning">
                        <strong>Compose editing is read-only.</strong>
                        <span>DockerManger can control this stack, but <?= $escape($storage['path']) ?> is not writable by the web process. Check the host bind-mount permissions before editing.</span>
                    </div>
                <?php endif; ?>

                <div class="stack-grid">
                    <section class="panel">
                        <div class="panel-heading">
                            <div><span class="eyebrow">Containers</span><h2>Stack Containers</h2></div>
                            <span class="count-badge"><?= count($selected['containers']) ?></span>
                        </div>
                        <?php if (!$selected['containers']): ?>
                            <div class="empty"><strong>No containers created yet</strong><span>Use Start to create and launch this stack.</span></div>
                        <?php else: ?>
                            <div class="container-cards">
                                <?php foreach ($selected['containers'] as $container): ?>
                                    <article class="container-card">
                                        <div>
                                            <span class="container-state <?= $escape($container['state']) ?>"><span class="status-dot"></span><?= $escape($container['status']) ?></span>
                                            <h3><a class="plain-link" href="/?container=<?= urlencode($container['name']) ?>"><?= $escape($container['name']) ?></a></h3>
                                            <p><?= $escape($container['image']) ?></p>
                                            <small><?= $escape($container['ports'] ?: 'No published ports') ?></small>
                                        </div>
                                        <div class="container-card-actions">
                                            <a class="button button-secondary button-small" href="/console.php?container=<?= urlencode($container['name']) ?>">Console</a>
                                            <button class="button button-secondary button-small log-container" data-container="<?= $escape($container['name']) ?>">Logs</button>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="panel">
                        <div class="panel-heading">
                            <div><span class="eyebrow">Compose</span><h2><?= $escape($selected['file']) ?></h2></div>
                            <span class="validity <?= $selected['valid'] ? 'good' : 'bad' ?>"><?= $selected['valid'] ? 'Valid' : 'Invalid' ?></span>
                        </div>
                        <form id="compose-editor" data-stack="<?= $escape($selected['name']) ?>" data-csrf="<?= $escape(csrf_token()) ?>">
                            <textarea class="compose-editor" name="compose" spellcheck="false"><?= $escape($composeText) ?></textarea>
                            <div class="editor-actions">
                                <button class="button" type="submit">Validate &amp; Save</button>
                                <span class="muted-cell">Changes are validated before replacing the current file.</span>
                            </div>
                        </form>
                    </section>
                </div>

                <section class="panel webui-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Service shortcuts</span><h2>App WebUIs</h2></div></div>
                    <p class="panel-description">Choose a container and shortcut. Compose labels <code>com.dockermanger.webui</code> and <code>com.dockermanger.webui.NAME</code> prefill URLs after deployment. Saving changes only DockerManger settings, not Compose.</p>
                    <?php if ($selected['containers']): ?>
                    <form class="app-webui-editor" data-csrf="<?= $escape(csrf_token()) ?>">
                        <label for="webui-app-select">App in this stack</label>
                        <select id="webui-app-select" name="container">
                            <?php foreach ($selected['containers'] as $app): ?>
                            <option value="<?= $escape($app['name']) ?>" data-links="<?= $escape(json_encode($app['webUiLinks'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>"><?= $escape($app['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="webui-link-select">Shortcut</label>
                        <select id="webui-link-select" name="webui_label"><option value="WebUI">WebUI (primary)</option></select>
                        <label for="webui-url">WebUI URL</label>
                        <div class="webui-editor-row">
                            <input id="webui-url" type="url" name="webui_url" placeholder="https://app.example.com" maxlength="2048">
                            <button type="submit" class="button">Save WebUI</button>
                        </div>
                        <small class="muted-cell">To add another link, select “Add shortcut”, give it a name, and save. Blank URL removes only the saved override; Compose labels remain defaults.</small>
                        <label for="webui-new-label">New shortcut name (letters, numbers, - or _)</label>
                        <input id="webui-new-label" name="new_label" maxlength="48" placeholder="admin" disabled>
                        <div class="webui-shortcut-links">
                        <?php foreach ($selected['containers'] as $app): ?>
                            <div class="webui-app-links" data-app="<?= $escape($app['name']) ?>">
                                <strong><?= $escape($app['name']) ?></strong>
                                <?php foreach ($app['webUiLinks'] as $label => $url): ?>
                                <a class="button button-secondary" href="<?= $escape($url) ?>" target="_blank" rel="noopener noreferrer">↗ <?= $escape($label) ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </form>
                    <?php else: ?><p>Deploy the stack to create containers and read their service labels.</p><?php endif; ?>
                </section>

                <section class="panel env-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Environment</span><h2>.env</h2></div><span class="count-badge">Stack file</span></div>
                    <p class="panel-description">Edit the conventional <code>.env</code> file used for Compose variable substitution. Treat values here as secrets when appropriate.</p>
                    <form id="env-editor" data-stack="<?= $escape($selected['name']) ?>" data-csrf="<?= $escape(csrf_token()) ?>">
                        <textarea class="compose-editor compose-editor-small" name="env" spellcheck="false" placeholder="APP_PORT=8080
TZ=America/Chicago"><?= $escape($envText ?? '') ?></textarea>
                        <div class="editor-actions"><button class="button" type="submit">Save .env</button><span class="muted-cell">Saved only inside this stack directory.</span></div>
                    </form>
                </section>

                <section class="panel stack-runtime-panel">
                    <div class="panel-heading"><div><span class="eyebrow">Runtime</span><h2>Stack Runtime &amp; Docker Inspect</h2></div><span class="count-badge"><?= (int) $selected['containerCount'] ?> containers</span></div>
                    <?php if (($selected['containers'] ?? []) === []): ?>
                        <div class="empty compact"><strong>No stack containers exist yet</strong><span>Runtime details will appear after the stack creates containers.</span></div>
                    <?php else: ?>
                        <div class="runtime-container-list">
                        <?php foreach ($selected['containers'] as $container): ?>
                            <?php $details = $stackContainerDetails[(string) $container['id']] ?? ['mounts' => [], 'networks' => []]; ?>
                            <article class="runtime-container-card">
                                <div class="runtime-container-title"><div><span class="container-state <?= $escape($container['state']) ?>"><span class="status-dot"></span><?= $escape($container['status']) ?></span><h3><?= $escape($container['name']) ?></h3><small><?= $escape($container['image']) ?></small></div><a class="button button-secondary button-small" href="/?container=<?= urlencode($container['name']) ?>">Container Details</a></div>
                                <div class="info-grid runtime-info-grid">
                                    <div><span>Ports</span><strong><?= $escape($container['ports'] ?: 'No published ports') ?></strong></div>
                                    <div><span>Restart policy</span><strong><?= $escape($details['restartPolicy'] ?? 'Unknown') ?></strong></div>
                                    <div><span>Privileged</span><strong><?= !empty($details['privileged']) ? 'Yes' : 'No' ?></strong></div>
                                    <div><span>Read-only rootfs</span><strong><?= !empty($details['readOnlyRootfs']) ? 'Yes' : 'No' ?></strong></div>
                                </div>
                                <div class="runtime-detail-grid">
                                    <div><h4>Networks</h4><?php if (($details['networks'] ?? []) === []): ?><p class="muted-cell">None reported</p><?php else: ?><?php foreach ($details['networks'] as $network): ?><p><strong><?= $escape($network['name']) ?></strong><small><?= $escape($network['ipAddress'] !== '' ? $network['ipAddress'] : 'no IP') ?></small></p><?php endforeach; ?><?php endif; ?></div>
                                    <div><h4>Mounts</h4><?php if (($details['mounts'] ?? []) === []): ?><p class="muted-cell">None reported</p><?php else: ?><?php foreach ($details['mounts'] as $mount): ?><p><strong><?= $escape(strtoupper((string) $mount['type'])) ?><?= !empty($mount['rw']) ? ' · RW' : ' · RO' ?></strong><small><?= $escape(($mount['source'] ?: '(Docker managed)') . ' → ' . $mount['destination']) ?></small></p><?php endforeach; ?><?php endif; ?></div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <div><span class="eyebrow">Logs</span><h2>Stack Logs</h2></div>
                        <button id="refresh-logs" class="button button-secondary button-small" data-stack="<?= $escape($selected['name']) ?>">Refresh Logs</button>
                    </div>
                    <pre id="stack-logs" class="log-viewer"><?= $escape($stackLogs['output'] ?? '') ?></pre>
                </section>
            <?php endif; ?>
        </main>
    </div>
</div>
<script src="/js/app.js"></script>
</body>
</html>
