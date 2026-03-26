<?php

/**
 * Neuron AI Retrieval backed by PHPAgentMemory's RecallEngine.
 *
 * Replaces the default SimilarityRetrieval with multi-collection recall
 * that combines memories + skills + knowledge, ranked by hybrid score.
 *
 * Usage in a Neuron RAG agent:
 *
 *   protected function retrieval(): RetrievalInterface {
 *       return new MemoryRetrieval($agentMemory, $embeddingProvider, 'agent1', 'user1');
 *   }
 */

declare(strict_types=1);

namespace PHPAgentMemory\Integration\Neuron;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Recall\RecallOptions;

final class MemoryRetrieval implements RetrievalInterface
{
    public function __construct(
        private readonly AgentMemory $memory,
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly string $agentId,
        private readonly string $userId,
        private readonly int $maxItems = 10,
    ) {}

    /**
     * Retrieve relevant documents from agent memory.
     *
     * @return Document[]
     */
    public function retrieve(Message $query): array
    {
        $text = $query->getContent();
        if (empty($text)) {
            return [];
        }

        $vector = $this->embeddings->embedText($text);

        $context = $this->memory->recall(
            $this->agentId,
            $this->userId,
            $text,
            $vector,
            new RecallOptions(maxItems: $this->maxItems),
        );

        $documents = [];

        // Convert memories to Documents
        foreach ($context->memories as $r) {
            $documents[] = $this->toDocument($r, 'memory', 'agent-memory');
        }

        // Convert skills to Documents
        foreach ($context->skills as $r) {
            $documents[] = $this->toDocument($r, 'skill', 'agent-memory');
        }

        // Convert knowledge to Documents
        foreach ($context->knowledge as $r) {
            $meta = $r['metadata'] ?? [];
            $source = $meta['source'] ?? 'agent-memory';
            $documents[] = $this->toDocument($r, 'knowledge', $source);
        }

        return $documents;
    }

    private function toDocument(array $result, string $sourceType, string $sourceName): Document
    {
        $meta = $result['metadata'] ?? [];
        $doc = new Document($meta['content'] ?? '');
        $doc->id = $result['id'];
        $doc->score = $result['score'];
        $doc->sourceType = $sourceType;
        $doc->sourceName = $sourceName;
        $doc->metadata = $meta;

        return $doc;
    }
}
