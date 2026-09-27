<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use DockerManger\Compose;
use DockerManger\Docker;
use DockerManger\Stack;
use DockerManger\SystemInfo;

/*
 * Narrow JSON control plane.
 *
 * Only explicit operations are accepted here. Do not turn this endpoint into a
 * generic shell or Docker command runner: access to the Docker socket is
 * effectively host-root access.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dockerClient = new Docker();
$composeClient = new Compose();
$systemClient = new SystemInfo();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();

        $action = strtolower(trim((string) ($_POST['action'] ?? '')));
        $stack = trim((string) ($_POST['stack'] ?? ''));
        $container = trim((string) ($_POST['container'] ?? ''));

        // The action list is deliberately closed: browser input selects an
        // operation but never supplies command-line arguments.
        $result = match ($action) {
            'stack-start' => $composeClient->up($stack),
            'stack-stop' => $composeClient->stop($stack),
            'stack-restart' => $composeClient->restart($stack),
            'stack-update' => $composeClient->update($stack),
            'stack-down' => $composeClient->down($stack),
            'container-start' => $dockerClient->start($container),
            'container-stop' => $dockerClient->stop($container),
            'container-restart' => $dockerClient->restart($container),
            'stack-save' => $composeClient->save($stack, (string) ($_POST['compose'] ?? '')),
            'stack-create' => $composeClient->create($stack, (string) ($_POST['compose'] ?? '')),
            default => throw new InvalidArgumentException('Unknown action.'),
        };

        $ok = isset($result['exitCode'])
            ? $result['exitCode'] === 0
            : !empty($result['ok']);

        respond(['ok' => $ok, 'action' => $action, 'result' => $result], $ok ? 200 : 422);
    }

    $resource = strtolower(trim((string) ($_GET['resource'] ?? 'system')));

    switch ($resource) {
        case 'system':
            respond([
                'ok' => true,
                'system' => $systemClient->get(),
                'docker' => $dockerClient->info(),
                'composeStorage' => $composeClient->storageStatus(),
            ]);

        case 'containers':
            $docker = $dockerClient->info();
            respond([
                'ok' => $docker['available'],
                'docker' => $docker,
                'containers' => $docker['available'] ? $dockerClient->containers() : [],
            ], $docker['available'] ? 200 : 503);

        case 'stacks':
            $docker = $dockerClient->info();
            $containers = $docker['available'] ? $dockerClient->containers() : [];
            $stacks = Stack::build($composeClient->stacks(), $containers);
            respond([
                'ok' => true,
                'stacksDir' => $composeClient->stacksDir(),
                'storage' => $composeClient->storageStatus(),
                'counts' => Stack::counts($stacks),
                'stacks' => $stacks,
            ]);

        case 'stack':
            $name = (string) ($_GET['stack'] ?? '');
            $docker = $dockerClient->info();
            $containers = $docker['available'] ? $dockerClient->containers() : [];
            $built = Stack::build($composeClient->stacks(), $containers);
            $found = null;

            foreach ($built as $item) {
                if ($item['name'] === $name) {
                    $found = $item;
                    break;
                }
            }

            if ($found === null) {
                respond(['ok' => false, 'error' => 'Stack not found.'], 404);
            }

            $found['compose'] = $composeClient->read($name);
            respond(['ok' => true, 'stack' => $found]);

        case 'container':
            $name = trim((string) ($_GET['container'] ?? ''));
            $container = $dockerClient->container($name);
            if ($container === null) {
                respond(['ok' => false, 'error' => 'Container not found.'], 404);
            }
            respond([
                'ok' => true,
                'container' => $container,
                'details' => $dockerClient->inspect($container['id']),
            ]);

        case 'logs':
            $stack = trim((string) ($_GET['stack'] ?? ''));
            $container = trim((string) ($_GET['container'] ?? ''));
            $result = $stack !== ''
                ? $composeClient->logs($stack, 250)
                : $dockerClient->logs($container, 250);
            respond([
                'ok' => $result['exitCode'] === 0,
                'output' => $result['output'],
            ], $result['exitCode'] === 0 ? 200 : 422);

        default:
            respond(['ok' => false, 'error' => 'Unknown API resource.'], 404);
    }
} catch (Throwable $e) {
    // Browser receives an actionable message; full PHP errors stay in logs.
    error_log('[DockerManger API] ' . $e->getMessage());
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}
