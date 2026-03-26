<?php

/**
 * Neuron AI Toolkit that gives agents memory tools.
 *
 * Exposes 4 tools:
 *   - memory_save: Save a new memory
 *   - memory_recall: Multi-collection recall (memories + skills + knowledge)
 *   - memory_search: Search a specific collection
 *   - memory_store_skill: Save a reusable skill/procedure
 *
 * Usage:
 *
 *   protected function tools(): array {
 *       return [
 *           new MemoryToolkit($agentMemory, $embeddingProvider, 'agent1', 'user1'),
 *       ];
 *   }
 */

declare(strict_types=1);

namespace PHPAgentMemory\Integration\Neuron;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Recall\RecallOptions;

final class MemoryToolkit implements ToolkitInterface
{
    /** @var class-string[] */
    private array $excludeClasses = [];

    /** @var class-string[] */
    private array $onlyClasses = [];

    /** @var array<string, \Closure> */
    private array $customizations = [];

    public function __construct(
        private readonly AgentMemory $memory,
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly string $agentId,
        private readonly string $userId,
    ) {}

    public function guidelines(): ?string
    {
        return <<<'GUIDE'
## Memory Tools
You have access to persistent memory. Use these tools to:
- **memory_save**: Save important facts, decisions, user preferences, or corrections. Always save when you learn something new about the user or project.
- **memory_recall**: Before answering complex questions, recall relevant context from your memory. This searches across memories, skills, and knowledge.
- **memory_search**: Search a specific collection (memory, skill, or knowledge) when you need targeted results.
- **memory_store_skill**: When you discover a useful procedure, configuration, or troubleshooting step, save it as a skill so you can recall it later.

Categories for memories: fact, decision, issue, task, correction.
Categories for skills: procedure, configuration, troubleshooting, workflow.
GUIDE;
    }

    /**
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        $tools = [];

        // memory_save
        $tools[] = Tool::make(
            'memory_save',
            'Save a new memory about the user, project, or conversation. Use for facts, decisions, issues, tasks, or corrections.',
        )
            ->addProperty(ToolProperty::make('content', PropertyType::STRING, 'The memory content to save', true))
            ->addProperty(ToolProperty::make('tags', PropertyType::STRING, 'Comma-separated tags (e.g. "preference,ui,theme")', false))
            ->addProperty(ToolProperty::make('category', PropertyType::STRING, 'Category: fact, decision, issue, task, or correction', false, ['fact', 'decision', 'issue', 'task', 'correction']))
            ->setCallable(function (string $content, string $tags = '', string $category = 'fact') {
                $vector = $this->embeddings->embedText($content);
                $tagArray = $tags !== '' ? array_map('trim', explode(',', $tags)) : [];

                $result = $this->memory->memories->saveOrUpdate(
                    $this->agentId,
                    $this->userId,
                    ['content' => $content, 'tags' => $tagArray, 'category' => $category],
                    $vector,
                );
                $this->memory->flush();

                $dedup = $result['deduplicated'] ? ' (updated existing)' : ' (new)';
                return "Saved memory{$dedup}: {$result['id']}";
            });

        // memory_recall
        $tools[] = Tool::make(
            'memory_recall',
            'Recall relevant context from all memory collections (memories, skills, knowledge). Use before answering complex questions.',
        )
            ->addProperty(ToolProperty::make('query', PropertyType::STRING, 'What to recall — describe what context you need', true))
            ->addProperty(ToolProperty::make('max_items', PropertyType::INTEGER, 'Maximum items to recall (default: 10)', false))
            ->setCallable(function (string $query, int $max_items = 10) {
                $vector = $this->embeddings->embedText($query);

                $context = $this->memory->recall(
                    $this->agentId,
                    $this->userId,
                    $query,
                    $vector,
                    new RecallOptions(maxItems: $max_items),
                );

                if ($context->totalItems === 0) {
                    return 'No relevant memories found.';
                }

                return $context->formatted;
            });

        // memory_search
        $tools[] = Tool::make(
            'memory_search',
            'Search a specific memory collection. Use when you need targeted results from memories, skills, or knowledge.',
        )
            ->addProperty(ToolProperty::make('query', PropertyType::STRING, 'Search query', true))
            ->addProperty(ToolProperty::make('collection', PropertyType::STRING, 'Collection to search: memory, skill, or knowledge', true, ['memory', 'skill', 'knowledge']))
            ->addProperty(ToolProperty::make('limit', PropertyType::INTEGER, 'Max results (default: 5)', false))
            ->setCallable(function (string $query, string $collection = 'memory', int $limit = 5) {
                $vector = $this->embeddings->embedText($query);

                $results = match ($collection) {
                    'skill' => $this->memory->skills->searchWithShared($this->agentId, $query, $vector, $limit),
                    'knowledge' => $this->memory->knowledge->searchWithShared($this->agentId, $query, $vector, $limit),
                    default => $this->memory->memories->search($this->agentId, $this->userId, $query, $vector, $limit),
                };

                if (empty($results)) {
                    return "No results found in {$collection} collection.";
                }

                $lines = [];
                foreach ($results as $r) {
                    $meta = $r['metadata'] ?? [];
                    $score = number_format($r['score'], 4);
                    $lines[] = "- [{$score}] {$meta['content']}";
                }
                return implode("\n", $lines);
            });

        // memory_store_skill
        $tools[] = Tool::make(
            'memory_store_skill',
            'Save a reusable skill, procedure, configuration, or troubleshooting step for future use.',
        )
            ->addProperty(ToolProperty::make('content', PropertyType::STRING, 'The skill/procedure content', true))
            ->addProperty(ToolProperty::make('tags', PropertyType::STRING, 'Comma-separated tags', false))
            ->addProperty(ToolProperty::make('category', PropertyType::STRING, 'Category: procedure, configuration, troubleshooting, or workflow', false, ['procedure', 'configuration', 'troubleshooting', 'workflow']))
            ->setCallable(function (string $content, string $tags = '', string $category = 'procedure') {
                $vector = $this->embeddings->embedText($content);
                $tagArray = $tags !== '' ? array_map('trim', explode(',', $tags)) : [];

                $result = $this->memory->skills->saveOrUpdate(
                    $this->agentId,
                    null,
                    ['content' => $content, 'tags' => $tagArray, 'category' => $category],
                    $vector,
                );
                $this->memory->flush();

                $dedup = $result['deduplicated'] ? ' (updated existing)' : ' (new)';
                return "Saved skill{$dedup}: {$result['id']}";
            });

        return $tools;
    }

    public function exclude(array $classes): ToolkitInterface
    {
        $this->excludeClasses = $classes;
        return $this;
    }

    public function only(array $classes): ToolkitInterface
    {
        $this->onlyClasses = $classes;
        return $this;
    }

    public function with(string $class, \Closure $callback): ToolkitInterface
    {
        $this->customizations[$class] = $callback;
        return $this;
    }
}
