# PHPAgentMemory

Agentic memory system for AI agents — built on [php-vector-store](https://github.com/MauricioPerera/php-vector-store). Zero external dependencies beyond php-vector-store itself.

Inspired by [RepoMemory](https://github.com/MauricioPerera/repomemory-v2)'s philosophy: save, search, recall, and consolidate knowledge — but pragmatic PHP instead of Git-like versioning.

```
composer require mauricioperera/php-agent-memory
```

## Why

AI agents lose context between sessions. PHPAgentMemory gives them persistent, searchable memory with:

- **3 entity types**: memories (what happened), skills (how to do things), knowledge (facts about the world)
- **Hybrid search**: vector similarity + BM25 full-text, fused with RRF
- **Matryoshka progressive search**: 128d→384d→768d — 5.6x faster than brute force at 5K vectors
- **Recall engine**: pools all collections, ranks by score, formats as context
- **Dream cycle**: 4-phase AI consolidation — orient, analyze, consolidate, verify — the agent "sleeps" and wakes up with cleaner memory
- **Neuron AI integration**: 4 adapters (VectorStore, Toolkit, Retrieval, PreProcessor)
- **HTTP API**: REST server with Bearer auth, ready for any agent framework
- **Zero dependencies**: only PHP 8.1+ and php-vector-store

## Quick Start

### As PHP Library

```php
use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Config;
use PHPAgentMemory\Consolidation\CloudflareLlmProvider;
use PHPAgentMemory\Recall\RecallOptions;

// Full setup with dream capability
$memory = new AgentMemory(new Config(
    dataDir: './data',
    dimensions: 768,
    quantized: true,
    llmProvider: new CloudflareLlmProvider('CF_ACCOUNT', 'CF_TOKEN'),
    embedFn: fn(string $text) => myEmbedFunction($text),
));

// Save memories (you provide pre-computed embedding vectors)
$memory->memories->save('agent1', 'user1', [
    'content' => 'The user prefers dark mode for all applications',
    'tags' => ['preference', 'ui'],
    'category' => 'fact',
], $embeddingVector);

// Save skills
$memory->skills->save('agent1', null, [
    'content' => 'To deploy: git push origin main, then run deploy.sh',
    'tags' => ['deploy', 'git'],
    'category' => 'procedure',
], $embeddingVector);

// Save knowledge
$memory->knowledge->save('agent1', null, [
    'content' => 'PHP 8.1 introduced enums and readonly properties',
    'tags' => ['php'],
    'source' => 'php.net',
], $embeddingVector);

// Recall: multi-collection search ranked by score
$context = $memory->recall('agent1', 'user1', 'deployment process', $queryVector,
    new RecallOptions(maxItems: 10),
);
echo $context->formatted;

// Dream: the agent sleeps and consolidates its memory
$report = $memory->dream('agent1', 'user1');
echo $report;

$memory->flush();
```

### As HTTP API

```bash
php -S 0.0.0.0:3210 bin/server.php -- --dir ./data --api-key SECRET --dimensions 768
```

```bash
# Save a memory
curl -X POST http://localhost:3210/memory/save \
  -H "Authorization: Bearer SECRET" \
  -H "Content-Type: application/json" \
  -d '{"agentId":"agent1","userId":"user1","content":"User prefers dark mode",
       "tags":["preference"],"category":"fact","vector":[0.1,-0.2,...]}'

# Recall across all collections
curl -X POST http://localhost:3210/recall \
  -H "Authorization: Bearer SECRET" \
  -H "Content-Type: application/json" \
  -d '{"agentId":"agent1","userId":"user1","query":"user preferences",
       "vector":[0.1,-0.2,...],"maxItems":10}'

# Dream: consolidate the agent's memory
curl -X POST http://localhost:3210/dream \
  -H "Authorization: Bearer SECRET" \
  -H "Content-Type: application/json" \
  -d '{"agentId":"agent1","userId":"user1"}'
```

## Architecture

```
┌────────────────────────────────────────────────────────────────────┐
│                         AgentMemory                                │
│                       (facade / entry point)                       │
│                                                                    │
│   save()  recall()  dream()  consolidate()  get()  delete()       │
├──────────┬───────────┬────────────┬────────────────────────────────┤
│ Memory   │  Skill    │ Knowledge  │  RecallEngine                  │
│Collection│Collection │ Collection │  (multi-collection pooled      │
│          │           │            │   search with weight           │
│          │           │            │   multipliers)                 │
├──────────┴───────────┴────────────┼────────────────────────────────┤
│       AbstractCollection          │         DreamPipeline          │
│  save, get, delete, search,       │   Phase 1: Orient (inventory)  │
│  saveOrUpdate, soft-delete,       │   Phase 2: Analyze (clusters)  │
│  list, count                      │   Phase 3: Consolidate (LLM)   │
│                                   │   Phase 4: Verify (integrity)  │
├───────────────────────────────────┼────────────────────────────────┤
│         php-vector-store          │  Cloudflare Workers AI          │
│  QuantizedStore + BM25 Index      │  EmbeddingGemma-300m (vectors) │
│  + HybridSearch (RRF)             │  Granite 4.0 H-Micro (LLM)    │
└───────────────────────────────────┴────────────────────────────────┘
```

## The Dream Cycle

The dream is a 4-phase reflective pass over the agent's memory — like how the brain consolidates memories during sleep.

```
  ┌───────────────────┐
  │  Agent works all   │     Saves memories, skills, knowledge
  │  day...            │     throughout the session
  └────────┬──────────┘
           │
           ▼
  ┌───────────────────┐     Takes inventory of what exists:
  │  Phase 1: Orient  │     "14 memories, 4 skills, 0 knowledge"
  └────────┬──────────┘
           │
           ▼
  ┌───────────────────┐     Finds duplicate clusters, stale entries:
  │  Phase 2: Analyze │     "5 duplicate clusters, 0 stale entries"
  └────────┬──────────┘
           │
           ▼
  ┌───────────────────┐     LLM decides: keep, merge, or remove
  │  Phase 3:         │     for each chunk of entities.
  │  Consolidate      │     Validates response (rejects hallucinated
  │  (via LLM)        │     IDs, empty content, >50 tags).
  └────────┬──────────┘
           │
           ▼
  ┌───────────────────┐     Verifies entity count and integrity:
  │  Phase 4: Verify  │     "10 entities remaining (delta: -8)"
  └────────┬──────────┘
           │
           ▼
  ┌───────────────────┐     Agent wakes up with cleaner, deduplicated
  │  Agent wakes up    │     memory. Ready for the next session.
  │  with clean memory │
  └───────────────────┘
```

### Usage

```php
// One-line dream — everything wired through Config
$report = $memory->dream('agent1', 'user1');

echo $report;
// Dream Report — agent:agent1 user:user1
// ──────────────────────────────────────────────────
//   [orient] Inventory: 14 memories, 4 skills, 0 knowledge
//   [analyze] Found 5 duplicate clusters, 0 stale entries
//   [consolidate] Memories: merged=2, removed=9, kept=6
//   [consolidate] Skills: merged=1, removed=2, kept=2
//   [verify] Post-dream: 10 entities (delta: -8)
// ──────────────────────────────────────────────────
//   Duration: 20,350ms
//   Merged: 3 | Removed: 11 | Kept: 8
```

### What the LLM Does During the Dream

Given these 14 memories:

```
3x "user prefers dark mode" (variants)     → merged into 1
3x "how to deploy to production" (variants) → merged into 1
2x "PostgreSQL is the database" (variants)  → kept best one
1x "API rate limit is 500 req/min"          → removed (contradicted)
1x "API rate limit increased to 2000"       → kept (correction)
+ 4 unique memories                         → kept unchanged
```

Result: 14 memories → 7 memories. Same knowledge, less noise.

### Programmatic Access to the Report

```php
$report = $memory->dream('agent1', 'user1');

$report->getTotalMerged();     // 3
$report->getTotalRemoved();    // 11
$report->getTotalKept();       // 8
$report->getDurationMs();      // 20350.0
$report->getConsolidations();  // ['memories' => [...], 'skills' => [...]]
$report->getLog();             // [{phase, message, time}, ...]
$report->toArray();            // Full report as array (for JSON APIs)
```

### Or Use the Low-Level Pipeline Directly

```php
use PHPAgentMemory\Dream\DreamPipeline;

$dream = new DreamPipeline(
    $memory,
    $llmProvider,
    fn(string $text) => myEmbedFunction($text),
);

$report = $dream->run('agent1', 'user1');
```

## Entity Types

### Memory
What happened — scoped by `agentId + userId`.

| Field | Type | Description |
|-------|------|-------------|
| content | string | The memory text |
| tags | string[] | Searchable tags (max 50, auto-lowercased) |
| category | enum | `fact`, `decision`, `issue`, `task`, `correction` |
| sourceSession | ?string | Optional session ID that produced this memory |

### Skill
How to do things — scoped by `agentId` only (no userId).

| Field | Type | Description |
|-------|------|-------------|
| content | string | The procedure/config/workflow |
| tags | string[] | Searchable tags |
| category | enum | `procedure`, `configuration`, `troubleshooting`, `workflow` |
| status | enum | `active`, `deprecated`, `draft` |

### Knowledge
Facts about the world — scoped by `agentId` only.

| Field | Type | Description |
|-------|------|-------------|
| content | string | The knowledge text |
| tags | string[] | Searchable tags |
| source | ?string | Where this knowledge came from |

## Scoping Model

Entities are isolated by scope using php-vector-store collections:

```
memories  → "mem-{agentId}-{userId}"     (per user per agent)
skills    → "skill-{agentId}"            (per agent, shared across users)
knowledge → "know-{agentId}"             (per agent, shared across users)
```

The special agent ID `_shared` enables cross-agent shared skills and knowledge. The recall engine automatically searches both the agent's own collections and `_shared`.

## Deduplication

Every `saveOrUpdate()` call:
1. Flushes pending writes
2. Runs hybrid search for similar existing entities
3. If score >= threshold (default 0.85): updates existing entity, merges tags
4. Otherwise: creates new entity

This prevents memory bloat from repeated saves of similar content.

## Configuration

```php
use PHPAgentMemory\Config;
use PHPAgentMemory\Consolidation\CloudflareLlmProvider;

$config = new Config(
    dataDir: './data',           // Where to store vectors and indices
    dimensions: 768,             // Must match your embedding model
    dedupThreshold: 0.85,        // Cosine similarity threshold for dedup
    quantized: true,             // Int8 (776 bytes/vec) vs Float32 (3072 bytes/vec)
    llmProvider: new CloudflareLlmProvider(  // Optional: for dream/consolidation
        accountId: 'CF_ACCOUNT_ID',
        apiToken: 'CF_API_TOKEN',
        model: '@cf/ibm-granite/granite-4.0-h-micro',
    ),
    embedFn: fn(string $text) => embed($text),  // Optional: for dream re-embedding
);
```

### CloudflareLlmProvider (included)

Zero-dependency LLM provider using Cloudflare Workers AI:

```php
$llm = new CloudflareLlmProvider(
    accountId: 'CF_ACCOUNT_ID',
    apiToken: 'CF_API_TOKEN',
    model: '@cf/ibm-granite/granite-4.0-h-micro',  // default
    maxTokens: 2048,
    temperature: 0.3,
);
```

Any Workers AI text generation model works. Recommended:
- `@cf/ibm-granite/granite-4.0-h-micro` — fastest, cheapest, good JSON output
- `@cf/meta/llama-3.1-8b-instruct` — better reasoning, slower

### Custom LLM Provider

```php
use PHPAgentMemory\Consolidation\LlmProviderInterface;

class MyLlmProvider implements LlmProviderInterface
{
    public function chat(array $messages): string
    {
        // Call OpenAI, Anthropic, Ollama, etc.
        return $response;
    }
}
```

## Neuron AI Integration

PHPAgentMemory integrates with [Neuron AI](https://github.com/neuron-core/neuron-ai) — the PHP agentic framework. Four adapters give any Neuron agent persistent memory:

### Option 1: Memory Tools (agent decides when to remember)

```php
use PHPAgentMemory\Integration\Neuron\MemoryToolkit;

class MyAgent extends \NeuronAI\Agent\Agent
{
    protected function tools(): array
    {
        return [
            new MemoryToolkit($agentMemory, $embeddingProvider, 'agent1', 'user1'),
        ];
    }
}
```

The toolkit exposes 4 tools the LLM can call:
- `memory_save` — Save facts, decisions, preferences, corrections
- `memory_recall` — Multi-collection recall (memories + skills + knowledge)
- `memory_search` — Search a specific collection
- `memory_store_skill` — Save reusable procedures

### Option 2: VectorStore Adapter (for RAG pipelines)

```php
use PHPAgentMemory\Integration\Neuron\NeuronMemoryStore;

class MyRAG extends \NeuronAI\RAG\RAG
{
    protected function vectorStore(): VectorStoreInterface
    {
        return new NeuronMemoryStore($agentMemory, 'agent1', 'knowledge', topK: 5);
    }
}
```

### Option 3: Memory Retrieval (replaces default retrieval)

```php
use PHPAgentMemory\Integration\Neuron\MemoryRetrieval;

class MyRAG extends \NeuronAI\RAG\RAG
{
    protected function retrieval(): RetrievalInterface
    {
        return new MemoryRetrieval($agentMemory, $embeddingProvider, 'agent1', 'user1', maxItems: 10);
    }
}
```

### Option 4: PreProcessor (automatic context injection)

```php
use PHPAgentMemory\Integration\Neuron\MemoryPreProcessor;

class MyRAG extends \NeuronAI\RAG\RAG
{
    protected function preProcessors(): array
    {
        return [
            new MemoryPreProcessor($agentMemory, $embeddingProvider, 'agent1', 'user1',
                maxItems: 5, maxChars: 2000),
        ];
    }
}
```

### Combining Everything

```php
class SmartAgent extends \NeuronAI\RAG\RAG
{
    protected function tools(): array
    {
        return [
            new MemoryToolkit($memory, $embeddings, 'agent1', 'user1'),
        ];
    }

    protected function preProcessors(): array
    {
        return [
            new MemoryPreProcessor($memory, $embeddings, 'agent1', 'user1'),
        ];
    }

    protected function retrieval(): RetrievalInterface
    {
        return new MemoryRetrieval($memory, $embeddings, 'agent1', 'user1');
    }
}

// End of day: the agent dreams
$memory->dream('agent1', 'user1');
```

## HTTP API Reference

| Method | Route | Body | Description |
|--------|-------|------|-------------|
| GET | `/health` | — | Status check (no auth) |
| GET | `/stats` | — | Storage statistics |
| POST | `/memory/save` | `{agentId, userId, content, tags, category, vector}` | Save/dedup memory |
| POST | `/memory/search` | `{agentId, userId, query, vector, limit?}` | Search memories |
| POST | `/skill/save` | `{agentId, content, tags, category, vector}` | Save/dedup skill |
| POST | `/skill/search` | `{agentId, query, vector, limit?}` | Search skills (+shared) |
| POST | `/knowledge/save` | `{agentId, content, tags, source, vector}` | Save/dedup knowledge |
| POST | `/knowledge/search` | `{agentId, query, vector, limit?}` | Search knowledge (+shared) |
| POST | `/recall` | `{agentId, userId, query, vector, maxItems?, weights?}` | Multi-collection recall |
| POST | `/consolidate` | `{agentId, userId?}` | AI consolidation (needs LLM) |
| POST | `/dream` | `{agentId, userId?}` | Full dream cycle (needs LLM + embedFn) |
| GET | `/entity/{id}` | — | Get any entity by ID |
| DELETE | `/entity/{id}` | — | Soft-delete entity |

**Auth**: `Authorization: Bearer <token>` (timing-safe comparison). `/health` is public.
**CORS**: Configurable via `--cors-origin` (default `*`).

## Benchmarks

Tested with [AG News dataset](https://huggingface.co/datasets/fancyzhx/ag_news) and [Cloudflare EmbeddingGemma-300m](https://developers.cloudflare.com/workers-ai/models/embeddinggemma-300m/) (768d Matryoshka, Int8 quantized).

### Search Performance

| Vectors | Brute 768d | Matryoshka 128→768 | 128d only | Speedup | Top-1 Accuracy |
|---------|-----------|-------------------|-----------|---------|----------------|
| 100 | 68ms | 90ms | 15ms | 0.7x | 100% |
| 500 | 297ms | 152ms | 60ms | 1.9x | 100% |
| 1,000 | 703ms | 230ms | 128ms | 3.1x | 100% |
| 2,000 | 1,320ms | 306ms | 262ms | 4.3x | 100% |
| 5,000 | 3,333ms | **596ms** | 438ms | **5.6x** | **100%** |

### Dream Performance

| Metric | Value |
|--------|-------|
| Entities before dream | 18 (14 memories + 4 skills) |
| Entities after dream | 10 |
| Duplicate clusters found | 5 |
| Merged groups | 3 |
| Entries removed | 11 |
| Duration | 20s |
| LLM model | Granite 4.0 H-Micro (Cloudflare Workers AI) |

### Other Metrics

| Metric | Value |
|--------|-------|
| Save latency | 1.0-2.0ms/entity |
| Flush to disk | 60-130ms |
| Storage per vector | 776 bytes (Int8 768d) |
| Storage at 5K vectors | 3.7 MB |
| Embedding (Cloudflare batch) | 23ms/text |
| Persistence reload | 7ms |

## Embedding Providers

PHPAgentMemory is embedding-agnostic — you pass pre-computed vectors to every `save()` and `search()` call. Use any provider:

### Cloudflare Workers AI (free)

```php
function embed(string $text): array {
    $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/@cf/google/embeddinggemma-300m");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", "Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode(['text' => [$text]]),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $data['result']['data'][0];
}
```

### OpenAI

```php
function embed(string $text): array {
    $ch = curl_init("https://api.openai.com/v1/embeddings");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$apiKey}", "Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode(['model' => 'text-embedding-3-small', 'input' => $text]),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $data['data'][0]['embedding'];
}
```

### Ollama (local)

```php
function embed(string $text): array {
    $ch = curl_init("http://localhost:11434/api/embed");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['model' => 'nomic-embed-text', 'input' => $text]),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $data['embeddings'][0];
}
```

## Storage Format

```
data/
├── vectors/
│   ├── mem-agent1-user1.q8.bin      ← Int8 vectors
│   ├── mem-agent1-user1.q8.json     ← Manifest (IDs + metadata)
│   ├── mem-agent1-user1.bm25.bin    ← BM25 inverted index
│   ├── skill-agent1.q8.bin
│   ├── skill-agent1.q8.json
│   ├── skill-agent1.bm25.bin
│   ├── know-agent1.q8.bin
│   ├── know-agent1.q8.json
│   └── know-agent1.bm25.bin
└── access-counts.json               ← Entity access tracking
```

## Testing

```bash
composer install
vendor/bin/phpunit                     # 43 tests, 88 assertions

# Benchmarks (require Cloudflare API credentials)
php tests/benchmark-cloudflare.php     # Functional benchmark (20 entities)
php -d memory_limit=512M tests/benchmark-scale.php --size 5000  # Scale benchmark

# Dream demo (requires Cloudflare API credentials)
php tests/demo-dream.php              # Watch the agent sleep and consolidate
```

## Project Structure

```
php-agent-memory/
├── src/
│   ├── AgentMemory.php                    # Facade: save, recall, dream, consolidate
│   ├── Config.php                         # dataDir, dimensions, llmProvider, embedFn
│   ├── Scoping.php                        # Scope → collection name mapping
│   ├── IdGenerator.php                    # {type}-{base36time}-{hex}
│   ├── AccessTracker.php                  # JSON-based access counting
│   ├── Entity/                            # DTOs + enums (7 files)
│   ├── Collection/                        # CRUD + search + dedup (5 files)
│   ├── Recall/                            # Multi-collection pooled search (4 files)
│   ├── Dream/                             # Sleep cycle pipeline (2 files)
│   │   ├── DreamPipeline.php              # 4-phase: orient→analyze→consolidate→verify
│   │   └── DreamReport.php                # Structured report with log
│   ├── Consolidation/                     # AI merge/prune engine (7 files)
│   │   ├── CloudflareLlmProvider.php      # Workers AI adapter (Granite, Llama, etc.)
│   │   └── ...
│   ├── Integration/Neuron/                # Neuron AI adapters (4 files)
│   │   ├── NeuronMemoryStore.php          # VectorStoreInterface
│   │   ├── MemoryToolkit.php              # 4 tools for agent use
│   │   ├── MemoryRetrieval.php            # RetrievalInterface
│   │   └── MemoryPreProcessor.php         # Auto context injection
│   └── Http/                              # REST API (8 files)
├── tests/
│   ├── 43 unit + integration tests
│   ├── benchmark-cloudflare.php           # Functional benchmark
│   ├── benchmark-scale.php                # Scale benchmark (100→5K vectors)
│   └── demo-dream.php                     # Dream demo with real AI
└── bin/
    └── server.php                         # HTTP server entry point
```

## RepoMemory Comparison

| Feature | RepoMemory (TS) | PHPAgentMemory |
|---------|-----------------|----------------|
| Language | TypeScript | PHP 8.1+ |
| Storage | Git-like (SHA-256 objects + commits) | php-vector-store (binary + JSON) |
| Versioning | Full commit history + tombstones | Soft-delete via metadata |
| Search | TF-IDF + neural embeddings | BM25 + vector hybrid (RRF) |
| Entity types | 5 (+ sessions, profiles) | 3 (memories, skills, knowledge) |
| Dream/Consolidation | AI pipeline | AI pipeline (4-phase dream cycle) |
| Agent integration | MCP server | Neuron AI (4 adapters) + HTTP API |
| Dependencies | 0 | 0 (beyond php-vector-store) |

PHPAgentMemory trades RepoMemory's Git-like versioning for simplicity. No commit chains, no tombstones, no audit log — just vectors, metadata, and soft-deletes. The core value (save, search, recall, dream) is preserved.

## License

MIT
