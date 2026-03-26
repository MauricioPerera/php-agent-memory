<?php

declare(strict_types=1);

namespace PHPAgentMemory\Http\Controller;

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Http\Router;

final class EntityController
{
    public static function register(Router $router, AgentMemory $memory): void
    {
        $router->get('/entity/{id}', function (array $data, array $params) use ($memory) {
            $id = $params['id'] ?? '';
            $entity = $memory->get($id);

            if ($entity === null) {
                return [404, ['error' => "Entity not found: {$id}"]];
            }

            return [200, $entity];
        });

        $router->delete('/entity/{id}', function (array $data, array $params) use ($memory) {
            $id = $params['id'] ?? '';
            $deleted = $memory->delete($id);

            if (!$deleted) {
                return [404, ['error' => "Entity not found: {$id}"]];
            }

            $memory->flush();
            return [200, ['deleted' => true, 'id' => $id]];
        });
    }
}
