<?php

declare(strict_types=1);

namespace PHPAgentMemory\Consolidation;

use PHPAgentMemory\Collection\CollectionInterface;

abstract class ConsolidationPipeline
{
    protected const CHUNK_SIZE = 20;

    public function __construct(
        protected readonly CollectionInterface $collection,
        protected readonly LlmProviderInterface $llm,
    ) {}

    abstract protected function systemPrompt(): string;

    abstract protected function listItems(string $agentId, ?string $userId): array;

    abstract protected function groupItems(array $items): array;

    /**
     * Run consolidation for a given scope.
     *
     * @return array{merged: int, removed: int, kept: int}
     */
    public function run(string $agentId, ?string $userId = null): array
    {
        $items = $this->listItems($agentId, $userId);

        if (count($items) < 2) {
            return ['merged' => 0, 'removed' => 0, 'kept' => count($items)];
        }

        $groups = $this->groupItems($items);
        $totalMerged = 0;
        $totalRemoved = 0;
        $totalKept = 0;

        foreach ($groups as $group) {
            foreach (array_chunk($group, self::CHUNK_SIZE) as $chunk) {
                $result = $this->processChunk($chunk, $agentId, $userId);
                $totalMerged += $result['merged'];
                $totalRemoved += $result['removed'];
                $totalKept += $result['kept'];
            }
        }

        return ['merged' => $totalMerged, 'removed' => $totalRemoved, 'kept' => $totalKept];
    }

    /**
     * @return array{merged: int, removed: int, kept: int}
     */
    private function processChunk(array $chunk, string $agentId, ?string $userId): array
    {
        if (count($chunk) < 2) {
            return ['merged' => 0, 'removed' => 0, 'kept' => count($chunk)];
        }

        // Serialize chunk for LLM
        $serialized = json_encode(
            array_map(fn($item) => [
                'id' => $item['id'],
                'content' => $item['content'] ?? '',
                'tags' => $item['tags'] ?? [],
                'category' => $item['category'] ?? 'fact',
                'createdAt' => $item['createdAt'] ?? '',
            ], $chunk),
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );

        // Call LLM
        $response = $this->llm->chat([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => Prompts::consolidationUser($serialized)],
        ]);

        // Parse and validate (reject hallucinated IDs)
        $validIds = array_map(fn($item) => $item['id'], $chunk);
        $plan = ConsolidationPlan::fromJson($response, $validIds);

        $merged = 0;
        $removed = 0;

        // Apply merges
        foreach ($plan->merge as $m) {
            $input = [
                'content' => $m['merged']['content'],
                'tags' => $m['merged']['tags'],
                'category' => $m['merged']['category'],
            ];

            // Use a zero vector since we don't have embeddings here
            // The caller should re-embed after consolidation if needed
            $dummyVector = array_fill(0, 384, 0.0);

            $this->collection->saveOrUpdate($agentId, $userId, $input, $dummyVector, 0.99);

            foreach ($m['sourceIds'] as $sourceId) {
                $this->collection->delete($sourceId);
                $removed++;
            }
            $merged++;
        }

        // Apply removes
        foreach ($plan->remove as $id) {
            $this->collection->delete($id);
            $removed++;
        }

        return [
            'merged' => $merged,
            'removed' => $removed,
            'kept' => count($plan->keep),
        ];
    }
}
