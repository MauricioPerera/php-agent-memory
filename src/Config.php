<?php

declare(strict_types=1);

namespace PHPAgentMemory;

use PHPAgentMemory\Consolidation\LlmProviderInterface;

final readonly class Config
{
    /** @var (callable(string): float[])|null */
    public mixed $embedFn;

    /**
     * @param callable(string): float[] $embedFn Optional embedding function for dream/consolidation re-embedding.
     */
    public function __construct(
        public string $dataDir,
        public int $dimensions = 384,
        public float $dedupThreshold = 0.85,
        public bool $quantized = true,
        public ?LlmProviderInterface $llmProvider = null,
        ?callable $embedFn = null,
    ) {
        $this->embedFn = $embedFn;
    }
}
