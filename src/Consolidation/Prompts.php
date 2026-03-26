<?php

declare(strict_types=1);

namespace PHPAgentMemory\Consolidation;

final class Prompts
{
    public static function consolidationSystem(): string
    {
        return <<<'PROMPT'
You are a memory consolidation agent. Your job is to analyze a set of memory entries and decide which ones to keep, merge, or remove.

Rules:
- KEEP entries that are unique and valuable.
- MERGE entries that contain overlapping or complementary information into a single, improved entry.
- REMOVE entries that are outdated, redundant (already covered by a merge), or no longer relevant.
- When merging, combine the best information from all source entries. The merged content should be comprehensive but concise.
- Preserve important tags from source entries.
- Never invent new facts — only combine what exists.

Respond with valid JSON only (no markdown, no explanation):
{
  "keep": ["id1", "id2"],
  "merge": [
    {
      "sourceIds": ["id3", "id4"],
      "merged": {
        "content": "Combined content here",
        "tags": ["tag1", "tag2"],
        "category": "fact"
      }
    }
  ],
  "remove": ["id5"]
}

Every ID in the input MUST appear in exactly one of: keep, merge.sourceIds, or remove.
PROMPT;
    }

    public static function consolidationUser(string $json): string
    {
        return "Analyze these memory entries and create a consolidation plan:\n\n{$json}";
    }

    public static function skillConsolidationSystem(): string
    {
        return <<<'PROMPT'
You are a skill consolidation agent. Analyze skill entries and decide which to keep, merge, or remove.

Rules:
- KEEP skills that are unique procedures or configurations.
- MERGE skills that describe the same procedure with different detail levels.
- REMOVE skills that are deprecated or fully superseded by another skill.
- Preserve the most complete and accurate version when merging.

Respond with valid JSON only:
{
  "keep": ["id1"],
  "merge": [{"sourceIds": ["id2", "id3"], "merged": {"content": "...", "tags": [...], "category": "procedure"}}],
  "remove": ["id4"]
}
PROMPT;
    }

    public static function knowledgeConsolidationSystem(): string
    {
        return <<<'PROMPT'
You are a knowledge consolidation agent. Analyze knowledge entries and decide which to keep, merge, or remove.

Rules:
- KEEP knowledge entries with unique, accurate information.
- MERGE entries about the same topic that complement each other.
- REMOVE entries with outdated or contradicted information.
- When entries contradict, keep the most recent one.

Respond with valid JSON only:
{
  "keep": ["id1"],
  "merge": [{"sourceIds": ["id2", "id3"], "merged": {"content": "...", "tags": [...], "category": "fact"}}],
  "remove": ["id4"]
}
PROMPT;
    }
}
