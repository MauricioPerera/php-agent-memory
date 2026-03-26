<?php

declare(strict_types=1);

namespace PHPAgentMemory\Http\Controller;

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Http\Router;
use PHPAgentMemory\Recall\RecallOptions;

final class RecallController
{
    public static function register(Router $router, AgentMemory $memory): void
    {
        $router->post('/recall', function (array $data) use ($memory) {
            $agentId = $data['agentId'] ?? '';
            $userId = $data['userId'] ?? '';
            $query = $data['query'] ?? '';
            $vector = $data['vector'] ?? [];

            if ($agentId === '' || $userId === '' || $query === '' || empty($vector)) {
                return [400, ['error' => 'Required: agentId, userId, query, vector']];
            }

            $options = new RecallOptions(
                maxItems: (int) ($data['maxItems'] ?? 20),
                maxChars: (int) ($data['maxChars'] ?? 8000),
                memoryWeight: (float) ($data['weights']['memory'] ?? 1.0),
                skillWeight: (float) ($data['weights']['skill'] ?? 1.0),
                knowledgeWeight: (float) ($data['weights']['knowledge'] ?? 1.0),
            );

            $context = $memory->recall($agentId, $userId, $query, $vector, $options);
            $memory->flush();

            return [200, $context->toArray()];
        });
    }
}
