<?php

declare(strict_types=1);

namespace PHPAgentMemory;

use PHPAgentMemory\Collection\KnowledgeCollection;
use PHPAgentMemory\Collection\MemoryCollection;
use PHPAgentMemory\Collection\SkillCollection;
use PHPAgentMemory\Consolidation\KnowledgeConsolidation;
use PHPAgentMemory\Consolidation\MemoryConsolidation;
use PHPAgentMemory\Consolidation\SkillConsolidation;
use PHPAgentMemory\Dream\DreamPipeline;
use PHPAgentMemory\Dream\DreamReport;
use PHPAgentMemory\Entity\EntityType;
use PHPAgentMemory\Recall\RecallContext;
use PHPAgentMemory\Recall\RecallEngine;
use PHPAgentMemory\Recall\RecallOptions;
use PHPVectorStore\BM25\Index as BM25Index;
use PHPVectorStore\HybridMode;
use PHPVectorStore\HybridSearch;
use PHPVectorStore\QuantizedStore;
use PHPVectorStore\StoreInterface;
use PHPVectorStore\VectorStore;

final class AgentMemory
{
    public readonly MemoryCollection $memories;
    public readonly SkillCollection $skills;
    public readonly KnowledgeCollection $knowledge;

    private readonly StoreInterface $store;
    private readonly BM25Index $bm25;
    private readonly HybridSearch $hybrid;
    private readonly AccessTracker $accessTracker;
    private readonly RecallEngine $recallEngine;
    private readonly Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $vectorDir = rtrim($config->dataDir, '/\\') . '/vectors';

        if (!is_dir($vectorDir)) {
            mkdir($vectorDir, 0755, true);
        }

        $this->store = $config->quantized
            ? new QuantizedStore($vectorDir, $config->dimensions)
            : new VectorStore($vectorDir, $config->dimensions);

        $this->bm25 = new BM25Index();
        $this->hybrid = new HybridSearch($this->store, $this->bm25, HybridMode::RRF);
        $this->accessTracker = new AccessTracker($config->dataDir);

        // Load existing BM25 indices
        foreach ($this->store->collections() as $col) {
            $this->bm25->load($vectorDir, $col);
        }

        // Create collections
        $this->memories = new MemoryCollection(
            $this->store, $this->bm25, $this->hybrid, $this->accessTracker, $config->dedupThreshold,
        );
        $this->skills = new SkillCollection(
            $this->store, $this->bm25, $this->hybrid, $this->accessTracker, $config->dedupThreshold,
        );
        $this->knowledge = new KnowledgeCollection(
            $this->store, $this->bm25, $this->hybrid, $this->accessTracker, $config->dedupThreshold,
        );

        $this->recallEngine = new RecallEngine(
            $this->memories, $this->skills, $this->knowledge, $this->accessTracker,
        );
    }

    /**
     * Multi-collection recall: pools memories + skills + knowledge, ranked by score.
     *
     * @param float[] $queryVector
     */
    public function recall(
        string $agentId,
        string $userId,
        string $query,
        array $queryVector,
        ?RecallOptions $options = null,
    ): RecallContext {
        return $this->recallEngine->recall($agentId, $userId, $query, $queryVector, $options);
    }

    /**
     * AI consolidation: merge duplicates, remove stale entries.
     * Requires an LlmProviderInterface in Config.
     *
     * @param string[] $types Entity types to consolidate (default: all)
     * @return array{memories?: array, skills?: array, knowledge?: array}
     */
    public function consolidate(string $agentId, ?string $userId = null, array $types = []): array
    {
        if ($this->config->llmProvider === null) {
            throw new \RuntimeException('Consolidation requires an LLM provider. Set llmProvider in Config.');
        }

        if (empty($types)) {
            $types = ['memory', 'skill', 'knowledge'];
        }

        $reports = [];

        if (in_array('memory', $types, true) && $userId !== null) {
            $pipeline = new MemoryConsolidation($this->memories, $this->config->llmProvider);
            $reports['memories'] = $pipeline->run($agentId, $userId);
        }

        if (in_array('skill', $types, true)) {
            $pipeline = new SkillConsolidation($this->skills, $this->config->llmProvider);
            $reports['skills'] = $pipeline->run($agentId);
        }

        if (in_array('knowledge', $types, true)) {
            $pipeline = new KnowledgeConsolidation($this->knowledge, $this->config->llmProvider);
            $reports['knowledge'] = $pipeline->run($agentId);
        }

        return $reports;
    }

    /**
     * Dream: full 4-phase memory consolidation (orient → analyze → consolidate → verify).
     * Requires both llmProvider and embedFn in Config.
     *
     * @param callable(string): float[] $embedFn Override embedding function (optional if set in Config).
     */
    public function dream(string $agentId, ?string $userId = null, ?callable $embedFn = null): DreamReport
    {
        if ($this->config->llmProvider === null) {
            throw new \RuntimeException('Dream requires an LLM provider. Set llmProvider in Config.');
        }

        $fn = $embedFn ?? $this->config->embedFn;
        if ($fn === null) {
            throw new \RuntimeException('Dream requires an embedding function. Set embedFn in Config or pass it as argument.');
        }

        $pipeline = new DreamPipeline($this, $this->config->llmProvider, $fn);
        $report = $pipeline->run($agentId, $userId);
        $this->flush();

        return $report;
    }

    /**
     * Get any entity by ID, scanning all collections.
     */
    public function get(string $id): ?array
    {
        return $this->memories->get($id)
            ?? $this->skills->get($id)
            ?? $this->knowledge->get($id);
    }

    /**
     * Soft-delete any entity by ID.
     */
    public function delete(string $id): bool
    {
        return $this->memories->delete($id)
            || $this->skills->delete($id)
            || $this->knowledge->delete($id);
    }

    /**
     * Aggregate stats from all stores.
     */
    public function stats(): array
    {
        return [
            'vectorStore' => $this->store->stats(),
            'collections' => $this->store->collections(),
            'dimensions' => $this->config->dimensions,
            'quantized' => $this->config->quantized,
        ];
    }

    /**
     * Flush all pending writes to disk.
     */
    public function flush(): void
    {
        $this->store->flush();

        $vectorDir = rtrim($this->config->dataDir, '/\\') . '/vectors';
        foreach ($this->store->collections() as $col) {
            $this->bm25->save($vectorDir, $col);
        }

        $this->accessTracker->flush();
    }

    public function getStore(): StoreInterface
    {
        return $this->store;
    }
}
