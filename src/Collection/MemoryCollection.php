<?php

declare(strict_types=1);

namespace PHPAgentMemory\Collection;

use PHPAgentMemory\Entity\EntityType;
use PHPAgentMemory\Entity\Memory;
use PHPAgentMemory\Entity\MemoryCategory;
use PHPAgentMemory\Scoping;

final class MemoryCollection extends AbstractCollection
{
    protected function entityType(): EntityType
    {
        return EntityType::Memory;
    }

    protected function collectionName(string $agentId, ?string $userId): string
    {
        return Scoping::memoryCollection($agentId, $userId ?? 'default');
    }

    protected function buildMetadata(string $id, string $agentId, ?string $userId, array $input): array
    {
        return [
            'type' => EntityType::Memory->value,
            'agentId' => $agentId,
            'userId' => $userId ?? 'default',
            'content' => $input['content'] ?? '',
            'tags' => array_map('strtolower', $input['tags'] ?? []),
            'category' => ($input['category'] ?? MemoryCategory::Fact->value),
            'sourceSession' => $input['sourceSession'] ?? null,
        ];
    }

    protected function entityFromMetadata(string $id, array $meta): array
    {
        return Memory::fromMetadata($id, $meta)->toArray();
    }
}
