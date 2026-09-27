<?php

declare(strict_types=1);

namespace DockerManger;

/**
 * Build UI-ready stack records by combining Compose discovery with Docker
 * container state.
 *
 * This keeps Docker/Compose interpretation out of templates and JavaScript.
 */
final class Stack
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const EXITED = 'exited';
    public const DEGRADED = 'degraded';

    /**
     * @param array<int,array<string,mixed>> $composeStacks
     * @param array<int,array<string,mixed>> $containers
     *
     * @return array<int,array<string,mixed>>
     */
    public static function build(array $composeStacks, array $containers): array
    {
        $containersByProject = [];

        foreach ($containers as $container) {
            $project = Docker::composeProject($container);

            if ($project === null) {
                continue;
            }

            $containersByProject[$project][] = $container;
        }

        $result = [];

        foreach ($composeStacks as $stack) {
            $name = (string) ($stack['name'] ?? '');
            $members = $containersByProject[$name] ?? [];

            $running = 0;
            $stopped = 0;

            foreach ($members as $container) {
                if (!empty($container['running'])) {
                    $running++;
                } else {
                    $stopped++;
                }
            }

            $valid = (bool) ($stack['valid'] ?? false);
            $total = count($members);

            if (!$valid) {
                $state = self::DEGRADED;
            } elseif ($total === 0) {
                $state = self::INACTIVE;
            } elseif ($running === $total) {
                $state = self::ACTIVE;
            } elseif ($running === 0) {
                $state = self::EXITED;
            } else {
                $state = self::DEGRADED;
            }

            $result[] = $stack + [
                'state' => $state,
                'running' => $running,
                'stopped' => $stopped,
                'containerCount' => $total,
                'containers' => $members,
            ];
        }

        return $result;
    }

    /**
     * @return array{active:int,inactive:int,exited:int,degraded:int}
     */
    public static function counts(array $stacks): array
    {
        $counts = [
            self::ACTIVE => 0,
            self::INACTIVE => 0,
            self::EXITED => 0,
            self::DEGRADED => 0,
        ];

        foreach ($stacks as $stack) {
            $state = (string) ($stack['state'] ?? self::INACTIVE);

            if (array_key_exists($state, $counts)) {
                $counts[$state]++;
            }
        }

        return $counts;
    }
}
