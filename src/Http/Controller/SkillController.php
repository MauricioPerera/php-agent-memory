<?php

declare(strict_types=1);

namespace PHPAgentMemory\Http\Controller;

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Http\Router;

final class SkillController
{
    public static function register(Router $router, AgentMemory $memory): void
    {
        $router->post('/skill/save', function (array $data) use ($memory) {
            $agentId = $data['agentId'] ?? '';
            $vector = $data['vector'] ?? [];

            if ($agentId === '' || empty($data['content']) || empty($vector)) {
                return [400, ['error' => 'Required: agentId, content, vector']];
            }

            $result = $memory->skills->saveOrUpdate($agentId, null, $data, $vector);
            $memory->flush();
            return [200, $result];
        });

        $router->post('/skill/search', function (array $data) use ($memory) {
            $agentId = $data['agentId'] ?? '';
            $query = $data['query'] ?? '';
            $vector = $data['vector'] ?? [];
            $limit = (int) ($data['limit'] ?? 10);

            if ($agentId === '' || $query === '' || empty($vector)) {
                return [400, ['error' => 'Required: agentId, query, vector']];
            }

            $results = $memory->skills->searchWithShared($agentId, $query, $vector, $limit);
            return [200, ['results' => $results]];
        });
    }
}
