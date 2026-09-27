<?php

declare(strict_types=1);

/**
 * Main browser entry point.
 *
 * This file prepares read-only view data and selects a template. State-changing
 * Docker/Compose operations remain behind api.php so templates never execute
 * host commands directly.
 */
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
$storage = $composeClient->storageStatus();
$managedProjects = array_column($stacks, 'name');
$thirdPartyContainers = array_values(array_filter($containers, static function (array $c) use ($managedProjects): bool {
    $project = Docker::composeProject($c);
    return $project === null || !in_array($project, $managedProjects, true);
}));

// A Compose stack and a standalone container are separate page types. Keeping
// them explicit avoids pretending externally-managed containers have a Compose
// file or Compose lifecycle semantics.
$selectedStackName = trim((string) ($_GET['stack'] ?? ''));
$selectedContainerName = trim((string) ($_GET['container'] ?? ''));

if ($selectedStackName !== '') {
    $selected = null;
    foreach ($stacks as $candidate) {
        if ($candidate['name'] === $selectedStackName) {
            $selected = $candidate;
            break;
        }
    }

    if ($selected === null) {
        http_response_code(404);
        $pageError = 'Compose stack not found.';
    } else {
        $composeText = $composeClient->read($selectedStackName);
        $envText = $composeClient->readEnv($selectedStackName);
        $stackLogs = $docker['available']
            ? $composeClient->logs($selectedStackName, 250)
            : ['output' => 'Docker Engine unavailable.'];
    }

    require dirname(__DIR__) . '/templates/stack.php';
    exit;
}

if ($selectedContainerName !== '') {
    if (!$docker['available']) {
        http_response_code(503);
        $pageError = 'Docker Engine is unavailable, so container details cannot be loaded.';
        $selectedContainer = null;
        $containerDetails = null;
        $containerLogs = ['output' => 'Docker Engine unavailable.'];
    } else {
        $selectedContainer = $dockerClient->container($selectedContainerName);
        if ($selectedContainer === null) {
            http_response_code(404);
            $pageError = 'Container not found.';
            $containerDetails = null;
            $containerLogs = ['output' => ''];
        } else {
            $containerDetails = $dockerClient->inspect($selectedContainer['id']);
            $containerLogs = $dockerClient->logs($selectedContainer['id'], 250);
        }
    }

    require dirname(__DIR__) . '/templates/container.php';
    exit;
}

$stackCounts = Stack::counts($stacks);
$running = count(array_filter($containers, static fn(array $c): bool => !empty($c['running'])));
$stopped = count($containers) - $running;
$invalidStacks = count(array_filter($stacks, static fn(array $s): bool => empty($s['valid'])));
require dirname(__DIR__) . '/templates/dashboard.php';
