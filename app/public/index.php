<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DockerManger\Compose;
use DockerManger\Docker;
use DockerManger\SystemInfo;

$docker = new Docker();
$compose = new Compose(getenv('STACKS_DIR') ?: '/opt/stacks');

$containers = $docker->containers();
$stacks = $compose->stacks();

$running = array_values(array_filter(
    $containers,
    fn(array $c): bool => strtolower($c['State'] ?? '') === 'running'
));

$stopped = count($containers) - count($running);
$validStacks = count(array_filter($stacks, fn(array $s): bool => $s['valid']));
$invalidStacks = count($stacks) - $validStacks;

require dirname(__DIR__) . '/templates/dashboard.php';
