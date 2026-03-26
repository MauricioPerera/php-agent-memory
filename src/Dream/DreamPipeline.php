<?php

/**
 * Dream Pipeline — Memory consolidation inspired by how the brain processes
 * memories during sleep.
 *
 * The "dream" is a multi-phase reflective pass over the agent's memory:
 *
 *   Phase 1: Orient    — inventory what exists
 *   Phase 2: Analyze   — find duplicates, contradictions, stale entries
 *   Phase 3: Consolidate — merge/prune via LLM
 *   Phase 4: Verify    — validate integrity after changes
 *
 * Usage:
 *   $dream = new DreamPipeline($memory, $llmProvider, $embeddingFn);
 *   $report = $dream->run('agent1', 'user1');
 */

declare(strict_types=1);

namespace PHPAgentMemory\Dream;

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Consolidation\LlmProviderInterface;
use PHPAgentMemory\Consolidation\ConsolidationPlan;
use PHPAgentMemory\Consolidation\Prompts;

final class DreamPipeline
{
    private const CHUNK_SIZE = 15; // Smaller chunks for small models
    private const MAX_ANALYSIS_ITEMS = 50;

    /** @var callable(string): float[] */
    private $embedFn;

    public function __construct(
        private readonly AgentMemory $memory,
        private readonly LlmProviderInterface $llm,
        callable $embedFn,
    ) {
        $this->embedFn = $embedFn;
    }

    /**
     * Run the full dream cycle.
     *
     * @return DreamReport
     */
    public function run(string $agentId, ?string $userId = null): DreamReport
    {
        $startTime = hrtime(true);
        $report = new DreamReport($agentId, $userId);

        // Phase 1: Orient
        $report->phase('orient');
        $inventory = $this->orient($agentId, $userId);
        $report->log("Inventory: {$inventory['memories']} memories, {$inventory['skills']} skills, {$inventory['knowledge']} knowledge");

        if ($inventory['total'] < 2) {
            $report->log('Too few entities to consolidate. Dream complete.');
            $report->finish(hrtime(true) - $startTime);
            return $report;
        }

        // Phase 2: Analyze — find clusters of similar content
        $report->phase('analyze');
        $analysis = $this->analyze($agentId, $userId, $inventory);
        $report->log("Found {$analysis['duplicateClusters']} potential duplicate clusters, {$analysis['staleCount']} stale entries");

        // Phase 3: Consolidate — merge and prune via LLM
        $report->phase('consolidate');

        if ($inventory['memories'] >= 2 && $userId !== null) {
            $memReport = $this->consolidateCollection('memories', $agentId, $userId);
            $report->log("Memories: merged={$memReport['merged']}, removed={$memReport['removed']}, kept={$memReport['kept']}");
            $report->addConsolidation('memories', $memReport);
        }

        if ($inventory['skills'] >= 2) {
            $skillReport = $this->consolidateCollection('skills', $agentId, null);
            $report->log("Skills: merged={$skillReport['merged']}, removed={$skillReport['removed']}, kept={$skillReport['kept']}");
            $report->addConsolidation('skills', $skillReport);
        }

        if ($inventory['knowledge'] >= 2) {
            $knowReport = $this->consolidateCollection('knowledge', $agentId, null);
            $report->log("Knowledge: merged={$knowReport['merged']}, removed={$knowReport['removed']}, kept={$knowReport['kept']}");
            $report->addConsolidation('knowledge', $knowReport);
        }

        // Phase 4: Verify
        $report->phase('verify');
        $postInventory = $this->orient($agentId, $userId);
        $delta = $inventory['total'] - $postInventory['total'];
        $report->log("Post-dream: {$postInventory['total']} entities (delta: -{$delta})");

        $this->memory->flush();

        $report->finish(hrtime(true) - $startTime);
        return $report;
    }

    /**
     * Phase 1: Take inventory of what exists.
     */
    private function orient(string $agentId, ?string $userId): array
    {
        $memories = $userId !== null ? $this->memory->memories->count($agentId, $userId) : 0;
        $skills = $this->memory->skills->count($agentId);
        $knowledge = $this->memory->knowledge->count($agentId);

        return [
            'memories' => $memories,
            'skills' => $skills,
            'knowledge' => $knowledge,
            'total' => $memories + $skills + $knowledge,
        ];
    }

    /**
     * Phase 2: Analyze for duplicates and stale entries.
     */
    private function analyze(string $agentId, ?string $userId, array $inventory): array
    {
        $duplicateClusters = 0;
        $staleCount = 0;
        $thirtyDaysAgo = date('c', strtotime('-30 days'));

        // Check memories for duplicates
        if ($userId !== null && $inventory['memories'] > 1) {
            $entities = $this->memory->memories->listEntities($agentId, $userId, self::MAX_ANALYSIS_ITEMS);
            $duplicateClusters += $this->countDuplicateClusters($entities);
            $staleCount += $this->countStale($entities, $thirtyDaysAgo);
        }

        // Check skills
        if ($inventory['skills'] > 1) {
            $entities = $this->memory->skills->listEntities($agentId, null, self::MAX_ANALYSIS_ITEMS);
            $duplicateClusters += $this->countDuplicateClusters($entities);
        }

        // Check knowledge
        if ($inventory['knowledge'] > 1) {
            $entities = $this->memory->knowledge->listEntities($agentId, null, self::MAX_ANALYSIS_ITEMS);
            $duplicateClusters += $this->countDuplicateClusters($entities);
        }

        return [
            'duplicateClusters' => $duplicateClusters,
            'staleCount' => $staleCount,
        ];
    }

    /**
     * Consolidate a specific collection using the LLM.
     *
     * @return array{merged: int, removed: int, kept: int}
     */
    private function consolidateCollection(string $type, string $agentId, ?string $userId): array
    {
        $collection = match ($type) {
            'memories' => $this->memory->memories,
            'skills' => $this->memory->skills,
            'knowledge' => $this->memory->knowledge,
        };

        $entities = $collection->listEntities($agentId, $userId, 200);

        if (count($entities) < 2) {
            return ['merged' => 0, 'removed' => 0, 'kept' => count($entities)];
        }

        // Group by category
        $groups = [];
        foreach ($entities as $e) {
            $cat = $e['category'] ?? 'general';
            $groups[$cat][] = $e;
        }

        $totalMerged = 0;
        $totalRemoved = 0;
        $totalKept = 0;

        foreach ($groups as $group) {
            foreach (array_chunk($group, self::CHUNK_SIZE) as $chunk) {
                if (count($chunk) < 2) {
                    $totalKept += count($chunk);
                    continue;
                }

                $result = $this->processChunk($chunk, $type, $agentId, $userId);
                $totalMerged += $result['merged'];
                $totalRemoved += $result['removed'];
                $totalKept += $result['kept'];
            }
        }

        return ['merged' => $totalMerged, 'removed' => $totalRemoved, 'kept' => $totalKept];
    }

    /**
     * Process a chunk of entities through the LLM.
     *
     * @return array{merged: int, removed: int, kept: int}
     */
    private function processChunk(array $chunk, string $type, string $agentId, ?string $userId): array
    {
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

        $systemPrompt = match ($type) {
            'skills' => Prompts::skillConsolidationSystem(),
            'knowledge' => Prompts::knowledgeConsolidationSystem(),
            default => Prompts::consolidationSystem(),
        };

        try {
            $response = $this->llm->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => Prompts::consolidationUser($serialized)],
            ]);
        } catch (\Throwable $e) {
            // LLM failed — keep everything unchanged
            return ['merged' => 0, 'removed' => 0, 'kept' => count($chunk)];
        }

        $validIds = array_map(fn($item) => $item['id'], $chunk);
        $plan = ConsolidationPlan::fromJson($response, $validIds);

        $merged = 0;
        $removed = 0;

        $collection = match ($type) {
            'memories' => $this->memory->memories,
            'skills' => $this->memory->skills,
            'knowledge' => $this->memory->knowledge,
        };

        // Apply merges
        foreach ($plan->merge as $m) {
            $embedFn = $this->embedFn;
            $vector = $embedFn($m['merged']['content']);

            $collection->saveOrUpdate($agentId, $userId, [
                'content' => $m['merged']['content'],
                'tags' => $m['merged']['tags'],
                'category' => $m['merged']['category'],
            ], $vector, 0.99);

            foreach ($m['sourceIds'] as $sourceId) {
                $collection->delete($sourceId);
                $removed++;
            }
            $merged++;
        }

        // Apply removals
        foreach ($plan->remove as $id) {
            $collection->delete($id);
            $removed++;
        }

        return [
            'merged' => $merged,
            'removed' => $removed,
            'kept' => count($plan->keep),
        ];
    }

    /**
     * Count groups of entities that might be duplicates (very basic heuristic:
     * entities sharing 3+ words in common in their content).
     */
    private function countDuplicateClusters(array $entities): int
    {
        $clusters = 0;
        $seen = [];

        for ($i = 0; $i < count($entities); $i++) {
            if (in_array($i, $seen, true)) continue;

            $words_i = array_unique(str_word_count(strtolower($entities[$i]['content'] ?? ''), 1));
            $clusterMembers = [];

            for ($j = $i + 1; $j < count($entities); $j++) {
                if (in_array($j, $seen, true)) continue;

                $words_j = array_unique(str_word_count(strtolower($entities[$j]['content'] ?? ''), 1));
                $overlap = count(array_intersect($words_i, $words_j));

                if ($overlap >= 3 && $overlap >= count($words_i) * 0.3) {
                    $clusterMembers[] = $j;
                }
            }

            if (!empty($clusterMembers)) {
                $clusters++;
                $seen = array_merge($seen, $clusterMembers);
            }
        }

        return $clusters;
    }

    /**
     * Count entities older than a given date.
     */
    private function countStale(array $entities, string $threshold): int
    {
        $count = 0;
        foreach ($entities as $e) {
            $created = $e['createdAt'] ?? '';
            if ($created !== '' && $created < $threshold) {
                $count++;
            }
        }
        return $count;
    }
}
