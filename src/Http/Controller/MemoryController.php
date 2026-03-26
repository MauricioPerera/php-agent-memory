<?php

declare(strict_types=1);

namespace PHPAgentMemory\Http\Controller;

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Http\Router;

final class MemoryController
{
    public static function register(Router $router, AgentMemory $memory): void
    {
        $router->post('/memory/save', function (array $data) use ($memory) {
            $agentId = $data['agentId'] ?? '';
            $userId = $data['userId'] ?? '';
            $vector = $data['vector'] ?? [];

            if ($agentId === '' || $userId === '' || empty($data['content']) || empty($vector)) {
                return [400, ['error' => 'Required: agentId, userId, content, vector']];
            }

            $result = $memory->memories->saveOrUpdate($agentId, $userId, $data, $vector);
            $memory->flush();
            return [200, $result];
        });

        $router->post('/memory/search', function (array $data) use ($memory) {
            $agentId = $data['agentId'] ?? '';
            $userId = $data['userId'] ?? '';
            $query = $data['query'] ?? '';
            $vector = $data['vector'] ?? [];
            $limit = (int) ($data['limit'] ?? 10);

            if ($agentId === '' || $userId === '' || $query === '' || empty($vector)) {
                return [400, ['error' => 'Required: agentId, userId, query, vector']];
            }

            $results = $memory->memories->search($agentId, $userId, $query, $vector, $limit);
            return [200, ['results' => $results]];
        });
    }
}
