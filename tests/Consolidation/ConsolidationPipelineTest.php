<?php

declare(strict_types=1);

namespace PHPAgentMemory\Tests\Consolidation;

use PHPUnit\Framework\TestCase;
use PHPAgentMemory\Consolidation\ConsolidationPlan;

final class ConsolidationPipelineTest extends TestCase
{
    public function testFromJsonValidPlan(): void
    {
        $json = json_encode([
            'keep' => ['id1'],
            'merge' => [[
                'sourceIds' => ['id2', 'id3'],
                'merged' => [
                    'content' => 'Combined content',
                    'tags' => ['tag1'],
                    'category' => 'fact',
                ],
            ]],
            'remove' => ['id4'],
        ]);

        $plan = ConsolidationPlan::fromJson($json, ['id1', 'id2', 'id3', 'id4']);

        $this->assertSame(['id1'], $plan->keep);
        $this->assertCount(1, $plan->merge);
        $this->assertSame(['id2', 'id3'], $plan->merge[0]['sourceIds']);
        $this->assertSame('Combined content', $plan->merge[0]['merged']['content']);
        $this->assertSame(['id4'], $plan->remove);
    }

    public function testRejectsHallucinatedIds(): void
    {
        $json = json_encode([
            'keep' => ['id1', 'fake-id'],
            'merge' => [[
                'sourceIds' => ['id2', 'hallucinated'],
                'merged' => ['content' => 'Test', 'tags' => [], 'category' => 'fact'],
            ]],
            'remove' => ['nonexistent'],
        ]);

        $plan = ConsolidationPlan::fromJson($json, ['id1', 'id2']);

        $this->assertSame(['id1'], $plan->keep);
        $this->assertSame(['id2'], $plan->merge[0]['sourceIds']);
        $this->assertEmpty($plan->remove); // nonexistent rejected
    }

    public function testRejectsEmptyMergedContent(): void
    {
        $json = json_encode([
            'keep' => [],
            'merge' => [[
                'sourceIds' => ['id1', 'id2'],
                'merged' => ['content' => '', 'tags' => [], 'category' => 'fact'],
            ]],
            'remove' => [],
        ]);

        $plan = ConsolidationPlan::fromJson($json, ['id1', 'id2']);
        $this->assertEmpty($plan->merge); // Empty content rejected
    }

    public function testHandlesInvalidJson(): void
    {
        $plan = ConsolidationPlan::fromJson('not json at all', ['id1', 'id2']);
        $this->assertSame(['id1', 'id2'], $plan->keep);
        $this->assertEmpty($plan->merge);
        $this->assertEmpty($plan->remove);
    }

    public function testHandlesMarkdownCodeBlock(): void
    {
        $json = "```json\n" . json_encode([
            'keep' => ['id1'],
            'merge' => [],
            'remove' => ['id2'],
        ]) . "\n```";

        $plan = ConsolidationPlan::fromJson($json, ['id1', 'id2']);
        $this->assertSame(['id1'], $plan->keep);
        $this->assertSame(['id2'], $plan->remove);
    }

    public function testCapsTags(): void
    {
        $manyTags = array_map(fn($i) => "tag{$i}", range(1, 60));
        $json = json_encode([
            'keep' => [],
            'merge' => [[
                'sourceIds' => ['id1'],
                'merged' => ['content' => 'Test', 'tags' => $manyTags, 'category' => 'fact'],
            ]],
            'remove' => [],
        ]);

        $plan = ConsolidationPlan::fromJson($json, ['id1']);
        $this->assertCount(50, $plan->merge[0]['merged']['tags']);
    }
}
