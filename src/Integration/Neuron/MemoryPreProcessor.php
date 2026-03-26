<?php

/**
 * Neuron AI PreProcessor that automatically enriches every query with recalled memory context.
 *
 * Injects relevant memories into the query as prefix context, so the RAG pipeline
 * sees both the user's question AND the agent's relevant memories.
 *
 * Usage in a Neuron RAG agent:
 *
 *   protected function preProcessors(): array {
 *       return [
 *           new MemoryPreProcessor($agentMemory, $embeddingProvider, 'agent1', 'user1'),
 *       ];
 *   }
 */

declare(strict_types=1);

namespace PHPAgentMemory\Integration\Neuron;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Recall\RecallOptions;

final class MemoryPreProcessor implements PreProcessorInterface
{
    public function __construct(
        private readonly AgentMemory $memory,
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly string $agentId,
        private readonly string $userId,
        private readonly int $maxItems = 5,
        private readonly int $maxChars = 2000,
    ) {}

    public function process(Message $question): Message
    {
        $query = $question->getContent();
        if (empty($query)) {
            return $question;
        }

        $vector = $this->embeddings->embedText($query);

        $context = $this->memory->recall(
            $this->agentId,
            $this->userId,
            $query,
            $vector,
            new RecallOptions(
                maxItems: $this->maxItems,
                maxChars: $this->maxChars,
            ),
        );

        if ($context->totalItems === 0) {
            return $question;
        }

        // Prepend memory context to the question
        $enriched = "<AGENT-MEMORY>\n{$context->formatted}\n</AGENT-MEMORY>\n\n{$query}";

        $role = MessageRole::tryFrom($question->getRole()) ?? MessageRole::USER;
        return new Message(role: $role, content: $enriched);
    }
}
