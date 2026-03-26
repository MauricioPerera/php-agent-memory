<?php

declare(strict_types=1);

namespace PHPAgentMemory\Collection;

use PHPAgentMemory\AccessTracker;
use PHPAgentMemory\Entity\EntityType;
use PHPAgentMemory\IdGenerator;
use PHPAgentMemory\Scoping;
use PHPVectorStore\BM25\Index as BM25Index;
use PHPVectorStore\HybridSearch;
use PHPVectorStore\StoreInterface;

abstract class AbstractCollection implements CollectionInterface
{
    private bool $dirty = false;

    public function __construct(
        protected readonly StoreInterface $store,
        protected readonly BM25Index $bm25,
        protected readonly HybridSearch $hybrid,
        protected readonly AccessTracker $accessTracker,
        protected readonly float $defaultDedupThreshold = 0.85,
    ) {}

    abstract protected function entityType(): EntityType;

    abstract protected function collectionName(string $agentId, ?string $userId): string;

    abstract protected function buildMetadata(string $id, string $agentId, ?string $userId, array $input): array;

    abstract protected function entityFromMetadata(string $id, array $meta): array;

    public function save(string $agentId, ?string $userId, array $input, array $vector): array
    {
        $id = IdGenerator::generate($this->entityType());
        $now = date('c');
        $col = $this->collectionName($agentId, $userId);

        $meta = $this->buildMetadata($id, $agentId, $userId, $input);
        $meta['createdAt'] = $now;
        $meta['updatedAt'] = $now;
        $meta['deleted'] = false;
        $meta['accessCount'] = 0;

        $this->store->set($col, $id, $vector, $meta);
        $this->bm25->addDocument($col, $id, $meta['content'] ?? '');
        $this->dirty = true;

        return ['id' => $id, 'metadata' => $meta];
    }

    public function saveOrUpdate(string $agentId, ?string $userId, array $input, array $vector, float $dedupThreshold = 0.85): array
    {
        $col = $this->collectionName($agentId, $userId);
        $content = $input['content'] ?? '';

        // Flush to ensure existing data is searchable
        if ($this->dirty) {
            $this->store->flush();
            $this->dirty = false;
        }

        // Search for similar existing entities
        $candidates = $this->hybrid->search($col, $vector, $content, 5);

        foreach ($candidates as $candidate) {
            $cMeta = $candidate['metadata'] ?? [];
            if (($cMeta['deleted'] ?? false)) {
                continue;
            }
            if ($candidate['score'] >= $dedupThreshold) {
                // Update existing
                $existingId = $candidate['id'];
                $now = date('c');

                $updated = array_merge($cMeta, [
                    'content' => $content,
                    'tags' => $this->mergeTags($cMeta['tags'] ?? [], $input['tags'] ?? []),
                    'updatedAt' => $now,
                ]);

                if (isset($input['category'])) {
                    $updated['category'] = $input['category'];
                }

                $this->store->set($col, $existingId, $vector, $updated);
                $this->bm25->removeDocument($col, $existingId);
                $this->bm25->addDocument($col, $existingId, $content);

                return ['id' => $existingId, 'metadata' => $updated, 'deduplicated' => true];
            }
        }

        // No match — create new
        $result = $this->save($agentId, $userId, $input, $vector);
        $result['deduplicated'] = false;

        return $result;
    }

    public function get(string $id): ?array
    {
        // Scan all collections of this type
        foreach ($this->store->collections() as $col) {
            if (!$this->isOwnCollection($col)) {
                continue;
            }
            $entry = $this->store->get($col, $id);
            if ($entry !== null) {
                $meta = $entry['metadata'] ?? [];
                if ($meta['deleted'] ?? false) {
                    return null;
                }
                return $this->entityFromMetadata($id, $meta);
            }
        }

        return null;
    }

    public function delete(string $id): bool
    {
        foreach ($this->store->collections() as $col) {
            if (!$this->isOwnCollection($col)) {
                continue;
            }
            $entry = $this->store->get($col, $id);
            if ($entry === null) {
                continue;
            }

            $meta = $entry['metadata'] ?? [];
            if ($meta['deleted'] ?? false) {
                return false;
            }

            $meta['deleted'] = true;
            $meta['updatedAt'] = date('c');

            $this->store->set($col, $id, $entry['vector'], $meta);
            $this->bm25->removeDocument($col, $id);

            return true;
        }

        return false;
    }

    public function listEntities(string $agentId, ?string $userId = null, int $limit = 100, int $offset = 0): array
    {
        $col = $this->collectionName($agentId, $userId);
        $ids = $this->store->ids($col);
        $results = [];

        foreach ($ids as $id) {
            $entry = $this->store->get($col, $id);
            if ($entry === null) {
                continue;
            }
            $meta = $entry['metadata'] ?? [];
            if ($meta['deleted'] ?? false) {
                continue;
            }
            $results[] = $this->entityFromMetadata($id, $meta);
        }

        return array_slice($results, $offset, $limit);
    }

    public function search(string $agentId, ?string $userId, string $query, array $queryVector, int $limit = 10): array
    {
        $col = $this->collectionName($agentId, $userId);

        if ($this->dirty) {
            $this->store->flush();
            $this->dirty = false;
        }
        $results = $this->hybrid->search($col, $queryVector, $query, $limit * 2);

        $filtered = [];
        foreach ($results as $r) {
            $meta = $r['metadata'] ?? [];
            if ($meta['deleted'] ?? false) {
                continue;
            }
            $this->accessTracker->increment($r['id']);
            $filtered[] = [
                'id' => $r['id'],
                'score' => $r['score'],
                'metadata' => $meta,
            ];
            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    public function count(string $agentId, ?string $userId = null): int
    {
        $col = $this->collectionName($agentId, $userId);
        $ids = $this->store->ids($col);
        $alive = 0;

        foreach ($ids as $id) {
            $entry = $this->store->get($col, $id);
            if ($entry !== null && !($entry['metadata']['deleted'] ?? false)) {
                $alive++;
            }
        }

        return $alive;
    }

    /** @return string[] */
    protected function mergeTags(array $existing, array $new): array
    {
        $merged = array_unique(array_merge(
            array_map('strtolower', $existing),
            array_map('strtolower', $new),
        ));

        return array_slice(array_values($merged), 0, 50);
    }

    protected function collectionPrefix(): string
    {
        return match ($this->entityType()) {
            EntityType::Memory => 'mem-',
            EntityType::Skill => 'skill-',
            EntityType::Knowledge => 'know-',
        };
    }

    private function isOwnCollection(string $col): bool
    {
        return str_starts_with($col, $this->collectionPrefix());
    }
}
