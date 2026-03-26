<?php

declare(strict_types=1);

namespace PHPAgentMemory\Consolidation;

final readonly class ConsolidationPlan
{
    /**
     * @param string[] $keep IDs to keep unchanged.
     * @param array<array{sourceIds: string[], merged: array{content: string, tags: string[], category: string}}> $merge
     * @param string[] $remove IDs to delete.
     */
    public function __construct(
        public array $keep,
        public array $merge,
        public array $remove,
    ) {}

    /**
     * Parse LLM JSON response into a validated plan.
     *
     * @param string $json Raw JSON from LLM.
     * @param string[] $validIds IDs that actually exist in the chunk (rejects hallucinations).
     */
    public static function fromJson(string $json, array $validIds): self
    {
        // Extract JSON from possible markdown code block
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $json, $m)) {
            $json = $m[1];
        }

        $data = json_decode(trim($json), true);
        if (!is_array($data)) {
            return new self(keep: $validIds, merge: [], remove: []);
        }

        $validSet = array_flip($validIds);

        // Validate keep
        $keep = [];
        foreach (($data['keep'] ?? []) as $id) {
            if (is_string($id) && isset($validSet[$id])) {
                $keep[] = $id;
            }
        }

        // Validate merge
        $merge = [];
        foreach (($data['merge'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $sourceIds = array_filter(
                $m['sourceIds'] ?? [],
                fn($id) => is_string($id) && isset($validSet[$id]),
            );
            $merged = $m['merged'] ?? [];
            $content = $merged['content'] ?? '';

            if (empty($sourceIds) || $content === '') {
                continue;
            }

            $tags = array_slice(
                array_filter($merged['tags'] ?? [], 'is_string'),
                0, 50,
            );

            $merge[] = [
                'sourceIds' => array_values($sourceIds),
                'merged' => [
                    'content' => $content,
                    'tags' => $tags,
                    'category' => $merged['category'] ?? 'fact',
                ],
            ];
        }

        // Validate remove
        $remove = [];
        foreach (($data['remove'] ?? []) as $id) {
            if (is_string($id) && isset($validSet[$id])) {
                $remove[] = $id;
            }
        }

        return new self(keep: $keep, merge: $merge, remove: $remove);
    }
}
