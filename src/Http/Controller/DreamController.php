<?php

declare(strict_types=1);

namespace PHPAgentMemory\Http\Controller;

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Http\Router;

final class DreamController
{
    public static function register(Router $router, AgentMemory $memory): void
    {
        $router->post('/dream', function (array $data) use ($memory) {
            $agentId = $data['agentId'] ?? '';

            if ($agentId === '') {
                return [400, ['error' => 'Required: agentId']];
            }

            try {
                $report = $memory->dream(
                    $agentId,
                    $data['userId'] ?? null,
                );
                return [200, $report->toArray()];
            } catch (\RuntimeException $e) {
                return [501, ['error' => $e->getMessage()]];
            }
        });
    }
}
