<?php

declare(strict_types=1);

namespace PHPAgentMemory\Collection;

use PHPAgentMemory\Entity\EntityType;
use PHPAgentMemory\Entity\Skill;
use PHPAgentMemory\Entity\SkillCategory;
use PHPAgentMemory\Entity\SkillStatus;
use PHPAgentMemory\Scoping;

final class SkillCollection extends AbstractCollection
{
    protected function entityType(): EntityType
    {
        return EntityType::Skill;
    }

    protected function collectionName(string $agentId, ?string $userId): string
    {
        return Scoping::skillCollection($agentId);
    }

    protected function buildMetadata(string $id, string $agentId, ?string $userId, array $input): array
    {
        return [
            'type' => EntityType::Skill->value,
            'agentId' => $agentId,
            'content' => $input['content'] ?? '',
            'tags' => array_map('strtolower', $input['tags'] ?? []),
            'category' => ($input['category'] ?? SkillCategory::Procedure->value),
            'status' => ($input['status'] ?? SkillStatus::Active->value),
        ];
    }

    protected function entityFromMetadata(string $id, array $meta): array
    {
        return Skill::fromMetadata($id, $meta)->toArray();
    }

    /**
     * Search including shared skills.
     *
     * @param float[] $queryVector
     * @return array<array{id: string, score: float, metadata: array}>
     */
    public function searchWithShared(string $agentId, string $query, array $queryVector, int $limit = 10): array
    {
        $collections = [Scoping::skillCollection($agentId)];
        if ($agentId !== Scoping::SHARED_AGENT_ID) {
            $collections[] = Scoping::sharedSkillCollection();
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
