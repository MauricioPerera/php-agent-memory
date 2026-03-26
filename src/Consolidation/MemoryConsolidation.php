<?php

declare(strict_types=1);

namespace PHPAgentMemory\Consolidation;

final class MemoryConsolidation extends ConsolidationPipeline
{
    protected function systemPrompt(): string
    {
        return Prompts::consolidationSystem();
    }

    protected function listItems(string $agentId, ?string $userId): array
    {
        return $this->collection->listEntities($agentId, $userId, 1000);
    }

    protected function groupItems(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $cat = $item['category'] ?? 'fact';
            $groups[$cat][] = $item;
        }
        return $groups;
    }
}
