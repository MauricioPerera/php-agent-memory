<?php

declare(strict_types=1);

namespace PHPAgentMemory\Collection;

interface CollectionInterface
{
    /**
     * Save a new entity. Returns [id, metadata].
     *
     * @param float[] $vector Pre-computed embedding vector.
     * @return array{id: string, metadata: array}
     */
    public function save(string $agentId, ?string $userId, array $input, array $vector): array;

    /**
     * Save or update: if similar entity exists (score >= threshold), update it; else create new.
     *
     * @return array{id: string, metadata: array, deduplicated: bool}
     */
    public function saveOrUpdate(string $agentId, ?string $userId, array $input, array $vector, float $dedupThreshold = 0.85): array;

    /**
     * Get entity by ID. Returns null if not found or soft-deleted.
     */
    public function get(string $id): ?array;

    /**
     * Soft-delete an entity. Returns true if found and deleted.
     */
    public function delete(string $id): bool;

    /**
     * List all non-deleted entities for a given scope.
     *
     * @return array[]
     */
    public function listEntities(string $agentId, ?string $userId = null, int $limit = 100, int $offset = 0): array;

    /**
     * Hybrid search (vector + BM25) within the scoped collection.
     *
     * @param float[] $queryVector
     * @return array<array{id: string, score: float, metadata: array}>
     */
    public function search(string $agentId, ?string $userId, string $query, array $queryVector, int $limit = 10): array;

    /**
     * Count non-deleted entities in scope.
     */
    public function count(string $agentId, ?string $userId = null): int;
}
