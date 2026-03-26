<?php

declare(strict_types=1);

namespace PHPAgentMemory\Collection;

use PHPAgentMemory\Entity\EntityType;
use PHPAgentMemory\Entity\Knowledge;
use PHPAgentMemory\Scoping;

final class KnowledgeCollection extends AbstractCollection
{
    protected function entityType(): EntityType
    {
        return EntityType::Knowledge;
    }

    protected function collectionName(string $agentId, ?string $userId): string
    {
        return Scoping::knowledgeCollection($agentId);
    }

    protected function buildMetadata(string $id, string $agentId, ?string $userId, array $input): array
    {
        return [
            'type' => EntityType::Knowledge->value,
            'agentId' => $agentId,
            'content' => $input['content'] ?? '',
            'tags' => array_map('strtolower', $input['tags'] ?? []),
            'source' => $input['source'] ?? null,
        ];
    }

    protected function entityFromMetadata(string $id, array $meta): array
    {
        return Knowledge::fromMetadata($id, $meta)->toArray();
    }

    /**
     * Search including shared knowledge.
     *
     * @param float[] $queryVector
     * @return array<array{id: string, score: float, metadata: array}>
     */
    public function searchWithShared(string $agentId, string $query, array $queryVector, int $limit = 10): array
    {
        $collections = [Scoping::knowledgeCollection($agentId)];
        if ($agentId !== Scoping::SHARED_AGENT_ID) {
            $collections[] = Scoping::sharedKnowledgeCollection();
        }

        $this->store->flush();
        $results = $this->hybrid->searchAcross($collections, $queryVector, $query, $limit * 2);

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
}
