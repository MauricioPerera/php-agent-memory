<?php

declare(strict_types=1);

namespace PHPAgentMemory\Http\Controller;

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Http\Router;

final class HealthController
{
    public static function register(Router $router, AgentMemory $memory): void
    {
        $router->get('/health', fn() => [200, ['status' => 'ok', 'version' => '0.1.0']]);

        $router->get('/stats', fn() => [200, $memory->stats()]);
    }
}
