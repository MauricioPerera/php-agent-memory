<?php

declare(strict_types=1);

namespace PHPAgentMemory\Recall;

final readonly class RecallOptions
{
    public function __construct(
        public int $maxItems = 20,
        public int $maxChars = 8000,
        public float $memoryWeight = 1.0,
        public float $skillWeight = 1.0,
        public float $knowledgeWeight = 1.0,
        public bool $includeSharedSkills = true,
        public bool $includeSharedKnowledge = true,
    ) {}
}
