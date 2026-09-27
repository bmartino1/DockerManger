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
$stacks = Stack::build($composeClient->stacks(), $containers);
$system = $systemClient->get();

$selectedName = trim((string)($_GET['stack'] ?? ''));
if ($selectedName !== '') {
    $selected = null;
    foreach ($stacks as $candidate) if ($candidate['name'] === $selectedName) { $selected = $candidate; break; }
    if ($selected === null) { http_response_code(404); $pageError = 'Compose stack not found.'; }
    else {
        $composeText = $composeClient->read($selectedName);
        $stackLogs = $docker['available'] ? $composeClient->logs($selectedName, 250) : ['output'=>'Docker Engine unavailable.'];
    }
    require dirname(__DIR__) . '/templates/stack.php';
    exit;
}

$stackCounts = Stack::counts($stacks);
$running = count(array_filter($containers, static fn(array $c): bool => !empty($c['running'])));
$stopped = count($containers) - $running;
$invalidStacks = count(array_filter($stacks, static fn(array $s): bool => empty($s['valid'])));
$managedProjects = array_column($stacks, 'name');
$thirdPartyContainers = array_values(array_filter($containers, static function(array $c) use ($managedProjects): bool {
    $project = Docker::composeProject($c);
    return $project === null || !in_array($project, $managedProjects, true);
}));
require dirname(__DIR__) . '/templates/dashboard.php';
