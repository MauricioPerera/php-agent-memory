<?php

declare(strict_types=1);

namespace PHPAgentMemory\Recall;

use PHPAgentMemory\AccessTracker;
use PHPAgentMemory\Collection\KnowledgeCollection;
use PHPAgentMemory\Collection\MemoryCollection;
use PHPAgentMemory\Collection\SkillCollection;

final class RecallEngine
{
    public function __construct(
        private readonly MemoryCollection $memories,
        private readonly SkillCollection $skills,
        private readonly KnowledgeCollection $knowledge,
        private readonly AccessTracker $accessTracker,
    ) {}

    /**
     * Multi-collection pooled search with weight multipliers.
     *
     * @param float[] $queryVector
     */
    public function recall(
        string $agentId,
        string $userId,
        string $query,
        array $queryVector,
        ?RecallOptions $options = null,
    ): RecallContext {
        $opts = $options ?? new RecallOptions();
        $fetchLimit = max(10, $opts->maxItems * 2);

        // Fetch from each collection
        $memResults = $this->memories->search($agentId, $userId, $query, $queryVector, $fetchLimit);
        $skillResults = $opts->includeSharedSkills
            ? $this->skills->searchWithShared($agentId, $query, $queryVector, $fetchLimit)
            : $this->skills->search($agentId, null, $query, $queryVector, $fetchLimit);
        $knowResults = $opts->includeSharedKnowledge
            ? $this->knowledge->searchWithShared($agentId, $query, $queryVector, $fetchLimit)
            : $this->knowledge->search($agentId, null, $query, $queryVector, $fetchLimit);

        // Apply weight multipliers
        foreach ($memResults as &$r) {
            $r['score'] *= $opts->memoryWeight;
            $r['_source'] = 'memory';
        }
        foreach ($skillResults as &$r) {
            $r['score'] *= $opts->skillWeight;
            $r['_source'] = 'skill';
        }
        foreach ($knowResults as &$r) {
            $r['score'] *= $opts->knowledgeWeight;
            $r['_source'] = 'knowledge';
        }

        // Pool and sort
        $pool = array_merge($memResults, $skillResults, $knowResults);
        usort($pool, fn($a, $b) => $b['score'] <=> $a['score']);
        $pool = array_slice($pool, 0, $opts->maxItems);

        // Distribute back to typed arrays
        $finalMemories = [];
        $finalSkills = [];
        $finalKnowledge = [];
        $ids = [];

        foreach ($pool as $item) {
            $ids[] = $item['id'];
            match ($item['_source']) {
                'memory' => $finalMemories[] = $item,
                'skill' => $finalSkills[] = $item,
                'knowledge' => $finalKnowledge[] = $item,
            };
        }

        // Increment access counts
        $this->accessTracker->incrementMany($ids);

        // Format
        $formatted = Formatter::format($finalMemories, $finalSkills, $finalKnowledge, $opts->maxChars);

        return new RecallContext(
            memories: $finalMemories,
            skills: $finalSkills,
            knowledge: $finalKnowledge,
            formatted: $formatted,
            totalItems: count($pool),
            estimatedChars: strlen($formatted),
        );
    }
}
