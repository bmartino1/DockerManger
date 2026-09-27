<?php

declare(strict_types=1);

/** Dedicated stack-creation page. Docker-run conversion is conversion only. */
require_once dirname(__DIR__) . '/src/bootstrap.php';

use DockerManger\Compose;
use DockerManger\SystemInfo;

$composeClient = new Compose();
$system = (new SystemInfo())->get();
$storage = $composeClient->storageStatus();
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="stylesheet" href="/css/app.css">
<title>Create Stack · DockerManger</title>
</head>
<body><div class="app-shell">
<header class="topbar"><a class="brand" href="/"><span class="logo">DM</span><span><strong>DockerManger</strong><small>Create managed Compose stack</small></span></a><div class="top-actions"><a class="button button-secondary" href="/">← Dashboard</a></div></header>
<main class="content create-page">
<section class="page-heading"><div><span class="eyebrow">Compose</span><h1>Create Stack</h1><p>Create a Compose stack directly, or convert a Docker run command into editable Compose first.</p></div></section>
<?php if (empty($storage['writable'])): ?><section class="notice notice-error"><strong>Compose storage is read-only.</strong><span><?= $escape($storage['path']) ?> must be writable before a stack can be created.</span></section><?php endif; ?>
<div class="stack-grid create-grid">
<section class="panel">
<div class="panel-heading"><div><span class="eyebrow">Docker Run</span><h2>Composerize</h2></div><span class="count-badge">Convert only</span></div>
<p class="panel-description">Paste a <code>docker run</code> command. DockerManger converts it to Compose; it does not execute the command.</p>
<form id="composerize-form" class="create-stack-form" data-csrf="<?= $escape(csrf_token()) ?>">
<label><span>docker run command</span><textarea class="compose-editor compose-editor-small" name="docker_run" required spellcheck="false" placeholder="docker run -d --name my-app -p 8080:80 nginx:alpine"></textarea></label>
<div class="editor-actions"><button class="button button-secondary" type="submit">Convert to Compose</button><a class="plain-link muted-cell" href="https://www.npmjs.com/package/composerize" target="_blank" rel="noopener noreferrer">Composerize package ↗</a></div>
</form><div id="composerize-result" class="operation-output" hidden></div>
</section>
<section class="panel">
<div class="panel-heading"><div><span class="eyebrow">Managed Stack</span><h2>compose.yaml</h2></div></div>
<form id="create-stack-form" class="create-stack-form" data-csrf="<?= $escape(csrf_token()) ?>">
<label><span>Stack name</span><input name="stack" required maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_.-]*" placeholder="my-stack"></label>
<label><span>Compose</span><textarea id="new-stack-compose" class="compose-editor compose-editor-small" name="compose" required spellcheck="false">services:
  app:
    image: nginx:alpine
    restart: unless-stopped
</textarea></label>
<label><span>.env (optional)</span><textarea class="compose-editor env-editor-small" name="env" spellcheck="false" placeholder="APP_PORT=8080
TZ=America/Chicago"></textarea></label>
<div class="editor-actions"><button class="button" type="submit">Validate &amp; Create</button><span class="muted-cell">Creates compose.yaml and an empty .env beneath <?= $escape($system['stacksDir']) ?>.</span></div>
</form><div id="create-stack-result" class="operation-output" hidden></div>
</section></div>
</main></div><script src="/js/app.js"></script></body></html>
