<?php

declare(strict_types=1);

namespace PHPAgentMemory\Http;

final class AuthMiddleware
{
    /**
     * Check Bearer token. Returns null if OK, or an error array if auth fails.
     *
     * @return array{0: int, 1: array}|null Null if authorized, [status, body] if not.
     */
    public static function check(?string $apiKey, string $authHeader, string $uri): ?array
    {
        if ($apiKey === null) {
            return null; // No auth configured
        }

        // Skip auth for health endpoint
        if (str_starts_with($uri, '/health')) {
            return null;
        }

        $token = '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        }

        if (!hash_equals($apiKey, $token)) {
            return [401, ['error' => 'Unauthorized: invalid or missing API key']];
        }

        return null;
    }
}
