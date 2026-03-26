<?php

/**
 * Neuron AI VectorStore adapter backed by PHPAgentMemory.
 *
 * Stores documents as knowledge entities in the agent's memory.
 * similaritySearch() returns recalled knowledge as Neuron Document objects.
 *
 * Usage in a Neuron RAG agent:
 *
 *   protected function vectorStore(): VectorStoreInterface {
 *       return new NeuronMemoryStore($agentMemory, 'agent1');
 *   }
 */

declare(strict_types=1);

namespace PHPAgentMemory\Integration\Neuron;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PHPAgentMemory\AgentMemory;

final class NeuronMemoryStore implements VectorStoreInterface
{
    private int $topK;

    public function __construct(
        private readonly AgentMemory $memory,
        private readonly string $agentId,
        private readonly string $collection = 'knowledge',
        int $topK = 5,
    ) {
        $this->topK = $topK;
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        $input = [
            'content' => $document->getContent(),
            'tags' => array_keys($document->metadata),
            'source' => $document->getSourceType() . ':' . $document->getSourceName(),
        ];

        $vector = $document->getEmbedding();
        if (empty($vector)) {
            return $this;
        }

        match ($this->collection) {
            'memory' => $this->memory->memories->saveOrUpdate(
                $this->agentId, 'system', $input, $vector,
            ),
            'skill' => $this->memory->skills->saveOrUpdate(
                $this->agentId, null, $input, $vector,
            ),
            default => $this->memory->knowledge->saveOrUpdate(
                $this->agentId, null, $input, $vector,
            ),
        };

        return $this;
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        foreach ($documents as $doc) {
            $this->addDocument($doc);
        }
        $this->memory->flush();
        return $this;
    }

    /**
     * @deprecated Use deleteBy() instead.
     */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $source = $sourceName !== null ? "{$sourceType}:{$sourceName}" : $sourceType;
        $collection = match ($this->collection) {
            'memory' => $this->memory->memories,
            'skill' => $this->memory->skills,
            default => $this->memory->knowledge,
        };

        $entities = $collection->listEntities($this->agentId, null, 1000);
        foreach ($entities as $entity) {
            $entitySource = $entity['source'] ?? '';
            if (str_starts_with($entitySource, $source)) {
                $collection->delete($entity['id']);
            }
        }

        $this->memory->flush();
        return $this;
    }

    /**
     * @param float[] $embedding
     * @return Document[]
     */
    public function similaritySearch(array $embedding): iterable
    {
        $collection = match ($this->collection) {
            'memory' => $this->memory->memories,
            'skill' => $this->memory->skills,
            default => $this->memory->knowledge,
        };

        $userId = $this->collection === 'memory' ? 'system' : null;
        $results = $collection->search($this->agentId, $userId, '', $embedding, $this->topK);

        $documents = [];
        foreach ($results as $r) {
            $meta = $r['metadata'] ?? [];
            $doc = new Document($meta['content'] ?? '');
            $doc->id = $r['id'];
            $doc->score = $r['score'];
            $doc->embedding = $embedding; // reuse query embedding for compatibility

            $source = $meta['source'] ?? 'memory:agent';
            $parts = explode(':', $source, 2);
            $doc->sourceType = $parts[0] ?? 'memory';
            $doc->sourceName = $parts[1] ?? 'agent';
            $doc->metadata = $meta;

            $documents[] = $doc;
        }

        return $documents;
    }
}
