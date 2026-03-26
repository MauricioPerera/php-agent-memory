<?php

/**
 * LLM Provider using Cloudflare Workers AI.
 *
 * Supports any text generation model on Workers AI.
 * Default: granite-4.0-h-micro (small, fast, good at JSON/instruction following).
 *
 * Usage:
 *   $llm = new CloudflareLlmProvider('ACCOUNT_ID', 'API_TOKEN');
 *   $memory = new AgentMemory(new Config(dataDir: './data', llmProvider: $llm));
 *   $memory->consolidate('agent1', 'user1'); // uses Granite for consolidation
 */

declare(strict_types=1);

namespace PHPAgentMemory\Consolidation;

final class CloudflareLlmProvider implements LlmProviderInterface
{
    private string $baseUrl;

    public function __construct(
        private readonly string $accountId,
        private readonly string $apiToken,
        private readonly string $model = '@cf/ibm-granite/granite-4.0-h-micro',
        private readonly int $maxTokens = 2048,
        private readonly float $temperature = 0.3,
    ) {
        $this->baseUrl = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$model}";
    }

    public function chat(array $messages): string
    {
        $payload = [
            'messages' => $messages,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Cloudflare AI request failed: curl error');
        }

        $data = json_decode($response, true);

        if (!($data['success'] ?? false)) {
            $error = $data['errors'][0]['message'] ?? 'Unknown error';
            throw new \RuntimeException("Cloudflare AI error (HTTP {$httpCode}): {$error}");
        }

        // Extract response text from chat completion format
        $result = $data['result'] ?? [];

        // Standard chat completion format
        if (isset($result['choices'][0]['message']['content'])) {
            return $result['choices'][0]['message']['content'];
        }

        // Legacy format
        if (isset($result['response'])) {
            return $result['response'];
        }

        throw new \RuntimeException('Unexpected Cloudflare AI response format');
    }
}
