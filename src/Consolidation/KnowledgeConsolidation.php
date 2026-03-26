<?php

declare(strict_types=1);

namespace PHPAgentMemory\Consolidation;

final class KnowledgeConsolidation extends ConsolidationPipeline
{
    protected function systemPrompt(): string
    {
        return Prompts::knowledgeConsolidationSystem();
    }

    protected function listItems(string $agentId, ?string $userId): array
    {
        return $this->collection->listEntities($agentId, null, 1000);
    }

    protected function groupItems(array $items): array
    {
        // Knowledge has no category grouping — single group
        return ['all' => $items];
    }
}
