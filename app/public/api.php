<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use DockerManger\Compose;
use DockerManger\Docker;
use DockerManger\SystemInfo;

$docker = new Docker();
$compose = new Compose(getenv('STACKS_DIR') ?: '/opt/stacks');

$resource = $_GET['resource'] ?? 'system';

switch ($resource) {
    case 'containers':
        json_response(['containers' => $docker->containers()]);

    case 'stacks':
        json_response(['stacks' => $compose->stacks()]);

    case 'system':
        json_response([
            'hostname' => SystemInfo::hostname(),
            'load_average' => SystemInfo::loadAverage(),
            'docker_available' => $docker->available(),
            'docker_version' => $docker->version(),
        ]);

    default:
        json_response(['error' => 'Unknown resource'], 404);
}
