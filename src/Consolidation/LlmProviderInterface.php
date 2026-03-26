<?php

declare(strict_types=1);

namespace PHPAgentMemory\Consolidation;

interface LlmProviderInterface
{
    /**
     * Send a chat completion request to an LLM.
     *
     * @param array<array{role: string, content: string}> $messages
     * @return string Raw text response from the LLM.
     */
    public function chat(array $messages): string;
}
