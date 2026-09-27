<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use DockerManger\Compose;
use DockerManger\Docker;
use DockerManger\Stack;
use DockerManger\SystemInfo;

$dockerClient = new Docker();
$composeClient = new Compose();
$systemClient = new SystemInfo();

$docker = $dockerClient->info();
$containers = $docker['available'] ? $dockerClient->containers() : [];
$composeStacks = $composeClient->stacks();
$stacks = Stack::build($composeStacks, $containers);
$stackCounts = Stack::counts($stacks);
$system = $systemClient->get();

$running = count(
    array_filter(
        $containers,
        static fn(array $container): bool => !empty($container['running'])
    )
);

$stopped = count($containers) - $running;

$invalidStacks = count(
    array_filter(
        $stacks,
        static fn(array $stack): bool => empty($stack['valid'])
    )
);

require dirname(__DIR__) . '/templates/dashboard.php';
