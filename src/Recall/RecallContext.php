<?php

declare(strict_types=1);

namespace PHPAgentMemory\Recall;

final readonly class RecallContext
{
    public function __construct(
        public array $memories,
        public array $skills,
        public array $knowledge,
        public string $formatted,
        public int $totalItems,
        public int $estimatedChars,
    ) {}

    public function toArray(): array
    {
        return [
            'memories' => $this->memories,
            'skills' => $this->skills,
            'knowledge' => $this->knowledge,
            'formatted' => $this->formatted,
            'totalItems' => $this->totalItems,
            'estimatedChars' => $this->estimatedChars,
        ];
    }
}
