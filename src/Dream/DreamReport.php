<?php

declare(strict_types=1);

namespace PHPAgentMemory\Dream;

final class DreamReport
{
    private string $currentPhase = '';
    private array $log = [];
    private array $consolidations = [];
    private float $durationMs = 0;

    public function __construct(
        public readonly string $agentId,
        public readonly ?string $userId,
    ) {}

    public function phase(string $name): void
    {
        $this->currentPhase = $name;
        $this->log[] = ['phase' => $name, 'message' => "=== Phase: {$name} ===", 'time' => date('c')];
    }

    public function log(string $message): void
    {
        $this->log[] = ['phase' => $this->currentPhase, 'message' => $message, 'time' => date('c')];
    }

    public function addConsolidation(string $type, array $report): void
    {
        $this->consolidations[$type] = $report;
    }

    public function finish(float $elapsedNs): void
    {
        $this->durationMs = $elapsedNs / 1_000_000;
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function getTotalMerged(): int
    {
        return array_sum(array_column($this->consolidations, 'merged'));
    }

    public function getTotalRemoved(): int
    {
        return array_sum(array_column($this->consolidations, 'removed'));
    }

    public function getTotalKept(): int
    {
        return array_sum(array_column($this->consolidations, 'kept'));
    }

    public function getConsolidations(): array
    {
        return $this->consolidations;
    }

    public function getLog(): array
    {
        return $this->log;
    }

    public function toArray(): array
    {
        return [
            'agentId' => $this->agentId,
            'userId' => $this->userId,
            'durationMs' => round($this->durationMs, 1),
            'totalMerged' => $this->getTotalMerged(),
            'totalRemoved' => $this->getTotalRemoved(),
            'totalKept' => $this->getTotalKept(),
            'consolidations' => $this->consolidations,
            'log' => array_map(fn($l) => "[{$l['phase']}] {$l['message']}", $this->log),
        ];
    }

    public function __toString(): string
    {
        $lines = ["Dream Report — agent:{$this->agentId} user:{$this->userId}"];
        $lines[] = str_repeat('─', 50);

        foreach ($this->log as $l) {
            $lines[] = "  [{$l['phase']}] {$l['message']}";
        }

        $lines[] = str_repeat('─', 50);
        $lines[] = "  Duration: " . number_format($this->durationMs, 0) . "ms";
        $lines[] = "  Merged: {$this->getTotalMerged()} | Removed: {$this->getTotalRemoved()} | Kept: {$this->getTotalKept()}";

        return implode("\n", $lines);
    }
}
