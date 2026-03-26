<?php

declare(strict_types=1);

namespace PHPAgentMemory\Http;

final class Router
{
    /** @var array<string, array<array{pattern: string, handler: callable}>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Dispatch a request.
     *
     * @return array{0: int, 1: array} [statusCode, responseData]
     */
    public function dispatch(string $method, string $uri, string $body): array
    {
        // Strip query string
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $data = $body !== '' ? json_decode($body, true) : [];
                if (!is_array($data)) {
                    $data = [];
                }

                try {
                    return ($route['handler'])($data, $params);
                } catch (\Throwable $e) {
                    return [400, ['error' => $e->getMessage()]];
                }
            }
        }

        return [404, ['error' => 'Not found', 'endpoints' => [
            'GET /health', 'GET /stats',
            'POST /memory/save', 'POST /memory/search',
            'POST /skill/save', 'POST /skill/search',
            'POST /knowledge/save', 'POST /knowledge/search',
            'POST /recall', 'POST /consolidate',
            'GET /entity/{id}', 'DELETE /entity/{id}',
        ]]];
    }

    private function addRoute(string $method, string $path, callable $handler): void
    {
        // Convert {param} to named regex groups
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[$method][] = ['pattern' => $pattern, 'handler' => $handler];
    }
}
