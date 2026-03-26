<?php

declare(strict_types=1);

namespace PHPAgentMemory\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Config;
use PHPAgentMemory\Recall\RecallOptions;

final class AgentMemoryTest extends TestCase
{
    private string $tmpDir;
    private AgentMemory $memory;
    private int $dim = 8; // Tiny dimension for tests

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pam-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->memory = new AgentMemory(new Config(
            dataDir: $this->tmpDir,
            dimensions: $this->dim,
            quantized: false,
        ));
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tmpDir);
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->cleanDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function randomVector(): array
    {
        return array_map(fn() => (float) mt_rand(-100, 100) / 100, range(1, $this->dim));
    }

    public function testSaveAndGetMemory(): void
    {
        $v = $this->randomVector();
        $result = $this->memory->memories->save('agent1', 'user1', [
            'content' => 'The user prefers dark theme',
            'tags' => ['preference', 'ui'],
            'category' => 'fact',
        ], $v);

        $this->assertArrayHasKey('id', $result);
        $this->assertStringStartsWith('memory-', $result['id']);

        $this->memory->flush();

        $entity = $this->memory->get($result['id']);
        $this->assertNotNull($entity);
        $this->assertSame('The user prefers dark theme', $entity['content']);
        $this->assertSame('fact', $entity['category']);
    }

    public function testSaveAndGetSkill(): void
    {
        $v = $this->randomVector();
        $result = $this->memory->skills->save('agent1', null, [
            'content' => 'To deploy, run: git push origin main',
            'tags' => ['deploy', 'git'],
            'category' => 'procedure',
        ], $v);

        $this->memory->flush();

        $entity = $this->memory->get($result['id']);
        $this->assertNotNull($entity);
        $this->assertSame('procedure', $entity['category']);
    }

    public function testSaveAndGetKnowledge(): void
    {
        $v = $this->randomVector();
        $result = $this->memory->knowledge->save('agent1', null, [
            'content' => 'PHP 8.1 introduced enums and readonly properties',
            'tags' => ['php', 'language'],
            'source' => 'php.net',
        ], $v);

        $this->memory->flush();

        $entity = $this->memory->get($result['id']);
        $this->assertNotNull($entity);
        $this->assertSame('php.net', $entity['source']);
    }

    public function testSoftDelete(): void
    {
        $v = $this->randomVector();
        $result = $this->memory->memories->save('agent1', 'user1', [
            'content' => 'Temporary note',
            'tags' => [],
        ], $v);
        $this->memory->flush();

        $deleted = $this->memory->delete($result['id']);
        $this->assertTrue($deleted);

        $entity = $this->memory->get($result['id']);
        $this->assertNull($entity);
    }

    public function testDeleteNonExistent(): void
    {
        $this->assertFalse($this->memory->delete('nonexistent-id'));
    }

    public function testRecall(): void
    {
        // Save entities across all three collections
        $v1 = $this->randomVector();
        $this->memory->memories->save('agent1', 'user1', [
            'content' => 'User likes blue color',
            'tags' => ['preference'],
            'category' => 'fact',
        ], $v1);

        $v2 = $this->randomVector();
        $this->memory->skills->save('agent1', null, [
            'content' => 'Change theme via settings menu',
            'tags' => ['theme'],
            'category' => 'procedure',
        ], $v2);

        $v3 = $this->randomVector();
        $this->memory->knowledge->save('agent1', null, [
            'content' => 'Blue is a calming color in design',
            'tags' => ['design'],
            'source' => 'design-guide',
        ], $v3);

        $this->memory->flush();

        $queryVector = $this->randomVector();
        $context = $this->memory->recall('agent1', 'user1', 'color preferences', $queryVector, new RecallOptions(
            maxItems: 10,
        ));

        $this->assertGreaterThan(0, $context->totalItems);
        $this->assertNotEmpty($context->formatted);
    }

    public function testListEntities(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->memory->memories->save('agent1', 'user1', [
                'content' => "Memory number {$i}",
                'tags' => [],
            ], $this->randomVector());
        }
        $this->memory->flush();

        $list = $this->memory->memories->listEntities('agent1', 'user1');
        $this->assertCount(5, $list);

        // Test pagination
        $page = $this->memory->memories->listEntities('agent1', 'user1', 2, 0);
        $this->assertCount(2, $page);
    }

    public function testStats(): void
    {
        $this->memory->memories->save('agent1', 'user1', [
            'content' => 'Test',
            'tags' => [],
        ], $this->randomVector());
        $this->memory->flush();

        $stats = $this->memory->stats();
        $this->assertArrayHasKey('vectorStore', $stats);
        $this->assertArrayHasKey('dimensions', $stats);
    }

    public function testSaveOrUpdateDedup(): void
    {
        $v = $this->randomVector();

        $r1 = $this->memory->memories->save('agent1', 'user1', [
            'content' => 'The user prefers dark mode',
            'tags' => ['preference'],
            'category' => 'fact',
        ], $v);
        $this->memory->flush();

        // Save very similar content with same vector — use very low threshold
        // since with tiny 8d vectors + RRF fusion, scores are small
        $r2 = $this->memory->memories->saveOrUpdate('agent1', 'user1', [
            'content' => 'The user prefers dark mode for coding',
            'tags' => ['preference', 'coding'],
            'category' => 'fact',
        ], $v, 0.01); // Very low threshold for 8d test vectors

        // Should have updated the existing one
        $this->assertTrue($r2['deduplicated']);
        $this->assertSame($r1['id'], $r2['id']);
    }

    public function testPersistence(): void
    {
        $v = $this->randomVector();
        $result = $this->memory->memories->save('agent1', 'user1', [
            'content' => 'Persistent memory',
            'tags' => ['test'],
        ], $v);
        $this->memory->flush();

        // Create new instance from same directory
        $memory2 = new AgentMemory(new Config(
            dataDir: $this->tmpDir,
            dimensions: $this->dim,
            quantized: false,
        ));

        $entity = $memory2->get($result['id']);
        $this->assertNotNull($entity);
        $this->assertSame('Persistent memory', $entity['content']);
    }
}
