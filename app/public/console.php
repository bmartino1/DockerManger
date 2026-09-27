<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

use DockerManger\Docker;

$dockerClient = new Docker();
$target = trim((string)($_GET['container'] ?? ''));
$container = $target !== '' ? $dockerClient->container($target) : null;
if ($target !== '' && $container === null) http_response_code(404);
$escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" href="/favicon.ico"><link rel="stylesheet" href="/css/app.css"><title>Console · DockerManger</title></head>
<body><div class="console-page"><header class="topbar"><a class="brand" href="/"><span class="logo">DM</span><span><strong>DockerManger</strong><small>Console</small></span></a><a class="button button-secondary" href="<?= $container ? '/?stack='.urlencode((string)(\DockerManger\Docker::composeProject($container) ?? '')) : '/' ?>">← Back</a></header>
<main class="content"><section class="page-heading"><span class="eyebrow">Console</span><h1><?= $container ? $escape($container['name']) : 'DockerManger Console' ?></h1><p>Interactive PTY console frontend placeholder.</p></section>
<section class="panel"><div class="panel-heading"><div><span class="eyebrow">Terminal</span><h2>Interactive console</h2></div></div><div class="console-placeholder"><strong>Console transport is not enabled yet.</strong><p>The PHP page and navigation are in place, but an interactive shell needs the planned xterm.js + Node/node-pty WebSocket service. DockerManger will not emulate a shell through arbitrary HTTP commands.</p><?php if($container): ?><p>Target: <code><?= $escape($container['name']) ?></code> · <?= $escape($container['image']) ?></p><?php endif; ?></div></section></main></div></body></html>
