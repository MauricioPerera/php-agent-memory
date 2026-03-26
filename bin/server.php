<?php

/**
 * PHPAgentMemory HTTP Server
 *
 * Usage: php -S 0.0.0.0:3210 bin/server.php
 * Or:    php bin/server.php --port 3210 --dir ./data --api-key SECRET
 */

declare(strict_types=1);

// Autoload
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Config;
use PHPAgentMemory\Http\AuthMiddleware;
use PHPAgentMemory\Http\CorsMiddleware;
use PHPAgentMemory\Http\Router;
use PHPAgentMemory\Http\Controller\HealthController;
use PHPAgentMemory\Http\Controller\MemoryController;
use PHPAgentMemory\Http\Controller\SkillController;
use PHPAgentMemory\Http\Controller\KnowledgeController;
use PHPAgentMemory\Http\Controller\RecallController;
use PHPAgentMemory\Http\Controller\ConsolidateController;
use PHPAgentMemory\Http\Controller\DreamController;
use PHPAgentMemory\Http\Controller\EntityController;

// Parse CLI arguments
$dir = './data';
$apiKey = null;
$corsOrigin = '*';
$dimensions = 384;
$quantized = true;

$args = array_slice($argv ?? [], 1);
for ($i = 0; $i < count($args); $i++) {
    match ($args[$i]) {
        '--dir' => $dir = $args[++$i] ?? $dir,
        '--api-key' => $apiKey = $args[++$i] ?? null,
        '--cors-origin' => $corsOrigin = $args[++$i] ?? '*',
        '--dimensions' => $dimensions = (int) ($args[++$i] ?? 384),
        '--float32' => $quantized = false,
        default => null,
    };
}

// Initialize
$config = new Config(
    dataDir: $dir,
    dimensions: $dimensions,
    quantized: $quantized,
);
$memory = new AgentMemory($config);

// Flush on shutdown
register_shutdown_function(fn() => $memory->flush());

// Build router
$router = new Router();
HealthController::register($router, $memory);
MemoryController::register($router, $memory);
SkillController::register($router, $memory);
KnowledgeController::register($router, $memory);
RecallController::register($router, $memory);
ConsolidateController::register($router, $memory);
DreamController::register($router, $memory);
EntityController::register($router, $memory);

// Handle request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$body = file_get_contents('php://input') ?: '';

// CORS
CorsMiddleware::headers($corsOrigin);
if (CorsMiddleware::handlePreflight($method)) {
    exit;
}

// Auth
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$authError = AuthMiddleware::check($apiKey, $authHeader, $uri);
if ($authError !== null) {
    http_response_code($authError[0]);
    header('Content-Type: application/json');
    echo json_encode($authError[1]);
    exit;
}

// Dispatch
[$status, $data] = $router->dispatch($method, $uri, $body);

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($data, JSON_UNESCAPED_SLASHES);
