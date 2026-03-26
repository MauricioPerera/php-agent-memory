<?php

declare(strict_types=1);

namespace PHPAgentMemory\Http\Controller;

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Http\Router;

final class ConsolidateController
{
    public static function register(Router $router, AgentMemory $memory): void
    {
        $router->post('/consolidate', function (array $data) use ($memory) {
            $agentId = $data['agentId'] ?? '';

            if ($agentId === '') {
                return [400, ['error' => 'Required: agentId']];
            }

            try {
                $reports = $memory->consolidate(
                    $agentId,
                    $data['userId'] ?? null,
                    $data['types'] ?? [],
                );
                $memory->flush();
                return [200, $reports];
            } catch (\RuntimeException $e) {
                return [501, ['error' => $e->getMessage()]];
            }
        });
    }
}
