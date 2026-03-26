<?php

declare(strict_types=1);

namespace PHPAgentMemory\Consolidation;

final class SkillConsolidation extends ConsolidationPipeline
{
    protected function systemPrompt(): string
    {
        return Prompts::skillConsolidationSystem();
    }

    protected function listItems(string $agentId, ?string $userId): array
    {
        return $this->collection->listEntities($agentId, null, 1000);
    }

    protected function groupItems(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $cat = $item['category'] ?? 'procedure';
            $groups[$cat][] = $item;
        }
        return $groups;
    }
}
