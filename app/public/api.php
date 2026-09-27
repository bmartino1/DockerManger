<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use DockerManger\Compose;
use DockerManger\Docker;
use DockerManger\Stack;
use DockerManger\SystemInfo;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$resource = strtolower(trim((string) ($_GET['resource'] ?? 'system')));

$dockerClient = new Docker();
$composeClient = new Compose();
$systemClient = new SystemInfo();

try {
    switch ($resource) {
        case 'system':
            respond([
                'ok' => true,
                'system' => $systemClient->get(),
                'docker' => $dockerClient->info(),
            ]);
            break;

        case 'containers':
            $docker = $dockerClient->info();

            respond([
                'ok' => $docker['available'],
                'docker' => $docker,
                'containers' => $docker['available']
                    ? $dockerClient->containers()
                    : [],
            ], $docker['available'] ? 200 : 503);
            break;

        case 'stacks':
            $docker = $dockerClient->info();
            $containers = $docker['available']
                ? $dockerClient->containers()
                : [];

            $stacks = Stack::build(
                $composeClient->stacks(),
                $containers
            );

            respond([
                'ok' => true,
                'stacksDir' => $composeClient->stacksDir(),
                'counts' => Stack::counts($stacks),
                'stacks' => $stacks,
            ]);
            break;

        default:
            respond([
                'ok' => false,
                'error' => 'Unknown API resource.',
            ], 404);
    }
} catch (Throwable $exception) {
    error_log('[DockerManger API] ' . $exception->getMessage());

    respond([
        'ok' => false,
        'error' => 'DockerManger could not complete the request.',
    ], 500);
}

/**
 * Emit a JSON response and stop request processing.
 *
 * @param array<string,mixed> $payload
 */
function respond(array $payload, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}
