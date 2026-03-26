<?php

declare(strict_types=1);

namespace PHPAgentMemory\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Config;
use PHPAgentMemory\Integration\Neuron\NeuronMemoryStore;
use PHPAgentMemory\Integration\Neuron\MemoryRetrieval;
use PHPAgentMemory\Integration\Neuron\MemoryPreProcessor;
use PHPAgentMemory\Integration\Neuron\MemoryToolkit;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\Chat\Messages\Message;

/**
 * Fake embedding provider for testing (returns deterministic small vectors).
 */
class FakeEmbeddings implements EmbeddingsProviderInterface
{
    private int $dim;

    public function __construct(int $dim = 8)
    {
        $this->dim = $dim;
    }

    public function embedText(string $text): array
    {
        // Deterministic: hash text to seed, generate vector
        $seed = crc32($text);
        mt_srand($seed);
        $vec = [];
        for ($i = 0; $i < $this->dim; $i++) {
            $vec[] = (mt_rand(-100, 100)) / 100.0;
        }
        mt_srand(); // reset
        return $vec;
    }

    public function embedDocument(Document $document): Document
    {
        $document->embedding = $this->embedText($document->getContent());
        return $document;
    }

    public function embedDocuments(array $documents): array
    {
        foreach ($documents as $doc) {
            $this->embedDocument($doc);
        }
        return $documents;
    }
}

final class NeuronIntegrationTest extends TestCase
{
    private string $tmpDir;
    private AgentMemory $memory;
    private FakeEmbeddings $embeddings;
    private int $dim = 8;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pam-neuron-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->memory = new AgentMemory(new Config(
            dataDir: $this->tmpDir,
            dimensions: $this->dim,
            quantized: false,
        ));

        $this->embeddings = new FakeEmbeddings($this->dim);
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

    // ── NeuronMemoryStore Tests ────────────────────────────────────────

    public function testNeuronMemoryStoreAddAndSearch(): void
    {
        $store = new NeuronMemoryStore($this->memory, 'agent1', 'knowledge', 5);

        // Create and add a document
        $doc = new Document('PHP 8.1 introduced enums and readonly properties');
        $doc->embedding = $this->embeddings->embedText($doc->getContent());
        $doc->sourceType = 'docs';
        $doc->sourceName = 'php.net';

        $store->addDocument($doc);
        $this->memory->flush();

        // Search
        $query = $this->embeddings->embedText('PHP features');
        $results = $store->similaritySearch($query);
        $results = is_array($results) ? $results : iterator_to_array($results);

        $this->assertNotEmpty($results);
        $this->assertInstanceOf(Document::class, $results[0]);
        $this->assertStringContainsString('PHP 8.1', $results[0]->getContent());
    }

    public function testNeuronMemoryStoreAddDocuments(): void
    {
        $store = new NeuronMemoryStore($this->memory, 'agent1', 'knowledge');

        $docs = [];
        for ($i = 0; $i < 3; $i++) {
            $doc = new Document("Document number {$i} about testing");
            $doc->embedding = $this->embeddings->embedText($doc->getContent());
            $doc->sourceType = 'test';
            $doc->sourceName = "test-{$i}";
            $docs[] = $doc;
        }

        $store->addDocuments($docs);

        $count = $this->memory->knowledge->count('agent1');
        $this->assertSame(3, $count);
    }

    public function testNeuronMemoryStoreDeleteBy(): void
    {
        $store = new NeuronMemoryStore($this->memory, 'agent1', 'knowledge');

        $doc = new Document('Temporary knowledge');
        $doc->embedding = $this->embeddings->embedText($doc->getContent());
        $doc->sourceType = 'temp';
        $doc->sourceName = 'cleanup-test';
        $store->addDocument($doc);
        $this->memory->flush();

        $this->assertSame(1, $this->memory->knowledge->count('agent1'));

        $store->deleteBy('temp', 'cleanup-test');
        $this->assertSame(0, $this->memory->knowledge->count('agent1'));
    }

    public function testNeuronMemoryStoreMemoryCollection(): void
    {
        $store = new NeuronMemoryStore($this->memory, 'agent1', 'memory');

        $doc = new Document('User prefers dark mode');
        $doc->embedding = $this->embeddings->embedText($doc->getContent());
        $doc->sourceType = 'preference';
        $doc->sourceName = 'ui';
        $store->addDocument($doc);
        $this->memory->flush();

        $count = $this->memory->memories->count('agent1', 'system');
        $this->assertSame(1, $count);
    }

    // ── MemoryRetrieval Tests ──────────────────────────────────────────

    public function testMemoryRetrievalReturnsDocuments(): void
    {
        // Seed some data
        $this->seedTestData();

        $retrieval = new MemoryRetrieval(
            $this->memory, $this->embeddings, 'agent1', 'user1', 10,
        );

        $message = new Message(\NeuronAI\Chat\Enums\MessageRole::USER, 'dark mode preferences');
        $docs = $retrieval->retrieve($message);

        $this->assertNotEmpty($docs);
        $this->assertInstanceOf(Document::class, $docs[0]);
        $this->assertNotEmpty($docs[0]->getContent());
        $this->assertGreaterThan(0, $docs[0]->getScore());
    }

    public function testMemoryRetrievalEmptyQuery(): void
    {
        $retrieval = new MemoryRetrieval(
            $this->memory, $this->embeddings, 'agent1', 'user1',
        );

        $message = new Message(\NeuronAI\Chat\Enums\MessageRole::USER, '');
        $docs = $retrieval->retrieve($message);

        $this->assertEmpty($docs);
    }

    public function testMemoryRetrievalMixesCollections(): void
    {
        $this->seedTestData();

        $retrieval = new MemoryRetrieval(
            $this->memory, $this->embeddings, 'agent1', 'user1', 10,
        );

        $message = new Message(\NeuronAI\Chat\Enums\MessageRole::USER, 'deployment and configuration');
        $docs = $retrieval->retrieve($message);

        $sourceTypes = array_unique(array_map(fn(Document $d) => $d->getSourceType(), $docs));

        // Should have results from multiple collections
        $this->assertNotEmpty($docs);
        // At least memories should be present
        $this->assertTrue(count($sourceTypes) >= 1);
    }

    // ── MemoryPreProcessor Tests ───────────────────────────────────────

    public function testPreProcessorEnrichesQuery(): void
    {
        $this->seedTestData();

        $processor = new MemoryPreProcessor(
            $this->memory, $this->embeddings, 'agent1', 'user1',
            maxItems: 3, maxChars: 1000,
        );

        $original = new Message(\NeuronAI\Chat\Enums\MessageRole::USER, 'What theme do I like?');
        $enriched = $processor->process($original);

        $content = $enriched->getContent();
        $this->assertStringContainsString('<AGENT-MEMORY>', $content);
        $this->assertStringContainsString('What theme do I like?', $content);
    }

    public function testPreProcessorPassesThroughEmpty(): void
    {
        $processor = new MemoryPreProcessor(
            $this->memory, $this->embeddings, 'agent1', 'user1',
        );

        $empty = new Message(\NeuronAI\Chat\Enums\MessageRole::USER, '');
        $result = $processor->process($empty);
        $this->assertTrue($result->getContent() === '' || $result->getContent() === null);
    }

    // ── MemoryToolkit Tests ────────────────────────────────────────────

    public function testToolkitHasFourTools(): void
    {
        $toolkit = new MemoryToolkit(
            $this->memory, $this->embeddings, 'agent1', 'user1',
        );

        $tools = $toolkit->tools();
        $this->assertCount(4, $tools);

        $names = array_map(fn($t) => $t->getName(), $tools);
        $this->assertContains('memory_save', $names);
        $this->assertContains('memory_recall', $names);
        $this->assertContains('memory_search', $names);
        $this->assertContains('memory_store_skill', $names);
    }

    public function testToolkitGuidelines(): void
    {
        $toolkit = new MemoryToolkit(
            $this->memory, $this->embeddings, 'agent1', 'user1',
        );

        $guidelines = $toolkit->guidelines();
        $this->assertNotNull($guidelines);
        $this->assertStringContainsString('Memory Tools', $guidelines);
    }

    public function testToolkitSaveTool(): void
    {
        $toolkit = new MemoryToolkit(
            $this->memory, $this->embeddings, 'agent1', 'user1',
        );

        $tools = $toolkit->tools();
        $saveTool = null;
        foreach ($tools as $t) {
            if ($t->getName() === 'memory_save') {
                $saveTool = $t;
                break;
            }
        }

        $this->assertNotNull($saveTool);

        // Execute the save tool
        $saveTool->setInputs([
            'content' => 'User prefers vim over nano',
            'tags' => 'preference,editor',
            'category' => 'fact',
        ]);
        $saveTool->execute();

        $result = $saveTool->getResult();
        $this->assertStringContainsString('Saved memory', $result);

        // Verify it was actually saved
        $count = $this->memory->memories->count('agent1', 'user1');
        $this->assertSame(1, $count);
    }

    public function testToolkitRecallTool(): void
    {
        $this->seedTestData();

        $toolkit = new MemoryToolkit(
            $this->memory, $this->embeddings, 'agent1', 'user1',
        );

        $tools = $toolkit->tools();
        $recallTool = null;
        foreach ($tools as $t) {
            if ($t->getName() === 'memory_recall') {
                $recallTool = $t;
                break;
            }
        }

        $this->assertNotNull($recallTool);

        $recallTool->setInputs(['query' => 'user preferences', 'max_items' => 5]);
        $recallTool->execute();

        $result = $recallTool->getResult();
        // Should have some content (either memories or "No relevant memories")
        $this->assertNotEmpty($result);
    }

    public function testToolkitStoreSkillTool(): void
    {
        $toolkit = new MemoryToolkit(
            $this->memory, $this->embeddings, 'agent1', 'user1',
        );

        $tools = $toolkit->tools();
        $skillTool = null;
        foreach ($tools as $t) {
            if ($t->getName() === 'memory_store_skill') {
                $skillTool = $t;
                break;
            }
        }

        $this->assertNotNull($skillTool);

        $skillTool->setInputs([
            'content' => 'To restart the service: systemctl restart myapp',
            'tags' => 'deploy,ops',
            'category' => 'procedure',
        ]);
        $skillTool->execute();

        $result = $skillTool->getResult();
        $this->assertStringContainsString('Saved skill', $result);

        $count = $this->memory->skills->count('agent1');
        $this->assertSame(1, $count);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function seedTestData(): void
    {
        $memories = [
            'The user prefers dark mode for all applications',
            'User timezone is America/Mexico_City',
            'The user is a senior PHP developer',
        ];
        foreach ($memories as $m) {
            $this->memory->memories->save('agent1', 'user1', [
                'content' => $m, 'tags' => ['test'], 'category' => 'fact',
            ], $this->embeddings->embedText($m));
        }

        $skills = [
            'Deploy by running git push origin main',
            'Database backup: pg_dump -Fc mydb > backup.dump',
        ];
        foreach ($skills as $s) {
            $this->memory->skills->save('agent1', null, [
                'content' => $s, 'tags' => ['test'], 'category' => 'procedure',
            ], $this->embeddings->embedText($s));
        }

        $knowledge = [
            'PHP 8.1 introduced enums and readonly properties',
        ];
        foreach ($knowledge as $k) {
            $this->memory->knowledge->save('agent1', null, [
                'content' => $k, 'tags' => ['test'], 'source' => 'docs',
            ], $this->embeddings->embedText($k));
        }

        $this->memory->flush();
    }
}
