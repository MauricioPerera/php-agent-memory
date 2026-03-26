<?php

declare(strict_types=1);

namespace PHPAgentMemory\Http;

final class CorsMiddleware
{
    public static function headers(string $origin = '*'): void
    {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }

    public static function handlePreflight(string $method): bool
    {
        if ($method === 'OPTIONS') {
            http_response_code(204);
            return true;
        }
        return false;
    }
}
