<?php

/**
 * PHPAgentMemory Benchmark — Real embeddings via Cloudflare EmbeddingGemma-300m
 *
 * Usage: php tests/benchmark-cloudflare.php
 *
 * Tests:
 * 1. Embedding latency (single + batch)
 * 2. Save performance (memories, skills, knowledge)
 * 3. Search accuracy (semantic relevance)
 * 4. Recall quality (multi-collection)
 * 5. Dedup detection
 * 6. Persistence roundtrip
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Config;
use PHPAgentMemory\Recall\RecallOptions;

// ── Cloudflare Config ──────────────────────────────────────────────────

$CF_ACCOUNT_ID = getenv('CF_ACCOUNT_ID') ?: die("Set CF_ACCOUNT_ID env var\n");
$CF_API_TOKEN  = getenv('CF_API_TOKEN') ?: die("Set CF_API_TOKEN env var\n");
define('CF_ACCOUNT_ID', $CF_ACCOUNT_ID);
define('CF_API_TOKEN', $CF_API_TOKEN);
const CF_MODEL      = '@cf/google/embeddinggemma-300m';
const DIMENSIONS    = 768;

// ── Helpers ────────────────────────────────────────────────────────────

function embed(string|array $text): array
{
    $payload = is_string($text) ? ['text' => [$text]] : ['text' => $text];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.cloudflare.com/client/v4/accounts/' . CF_ACCOUNT_ID . '/ai/run/' . CF_MODEL,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . CF_API_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if (!$data || !($data['success'] ?? false) || empty($data['result']['data'])) {
        throw new RuntimeException("Embedding failed (HTTP {$httpCode}): " . ($data['errors'][0]['message'] ?? 'unknown'));
    }

    return $data['result']['data'];
}

function embedSingle(string $text): array
{
    return embed($text)[0];
}

function timer(callable $fn): array
{
    $start = hrtime(true);
    $result = $fn();
    $elapsed = (hrtime(true) - $start) / 1_000_000; // ms
    return [$result, $elapsed];
}

function printHeader(string $title): void
{
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "  {$title}\n";
    echo str_repeat('=', 60) . "\n";
}

function printResult(string $label, float $ms, string $extra = ''): void
{
    $msStr = number_format($ms, 1);
    echo "  {$label}: {$msStr}ms";
    if ($extra) echo " — {$extra}";
    echo "\n";
}

function cosineSim(array $a, array $b): float
{
    $dot = 0; $na = 0; $nb = 0;
    $dims = min(count($a), count($b));
    for ($i = 0; $i < $dims; $i++) {
        $dot += $a[$i] * $b[$i];
        $na += $a[$i] * $a[$i];
        $nb += $b[$i] * $b[$i];
    }
    $denom = sqrt($na) * sqrt($nb);
    return $denom > 0 ? $dot / $denom : 0.0;
}

function cleanDir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? cleanDir($path) : unlink($path);
    }
    rmdir($dir);
}

// ── Test Data ──────────────────────────────────────────────────────────

$memoryData = [
    ['content' => 'The user prefers dark mode for all applications', 'tags' => ['preference', 'ui'], 'category' => 'fact'],
    ['content' => 'User timezone is America/Mexico_City (UTC-6)', 'tags' => ['preference', 'timezone'], 'category' => 'fact'],
    ['content' => 'The user is a senior PHP developer with 10 years experience', 'tags' => ['profile', 'skills'], 'category' => 'fact'],
    ['content' => 'Decided to use PostgreSQL instead of MySQL for the new project', 'tags' => ['database', 'decision'], 'category' => 'decision'],
    ['content' => 'The deployment pipeline has a bug: staging env does not run migrations', 'tags' => ['devops', 'bug'], 'category' => 'issue'],
    ['content' => 'Need to refactor the authentication module before Q2 launch', 'tags' => ['auth', 'refactor', 'deadline'], 'category' => 'task'],
    ['content' => 'User prefers composer over manual dependency management', 'tags' => ['preference', 'php'], 'category' => 'fact'],
    ['content' => 'The API rate limit was increased to 1000 req/min after the scaling incident', 'tags' => ['api', 'scaling'], 'category' => 'correction'],
    ['content' => 'User speaks Spanish and English fluently', 'tags' => ['language', 'profile'], 'category' => 'fact'],
    ['content' => 'The user prefers concise responses without unnecessary explanations', 'tags' => ['preference', 'communication'], 'category' => 'fact'],
];

$skillData = [
    ['content' => 'To deploy to production: git push origin main, then run deploy.sh on the server', 'tags' => ['deploy', 'git'], 'category' => 'procedure'],
    ['content' => 'Database backup: run pg_dump -Fc mydb > backup.dump every night at 2am UTC', 'tags' => ['database', 'backup'], 'category' => 'procedure'],
    ['content' => 'Redis config: maxmemory 256mb, maxmemory-policy allkeys-lru, bind 127.0.0.1', 'tags' => ['redis', 'config'], 'category' => 'configuration'],
    ['content' => 'When Laravel queue fails: check Redis connection, restart horizon, check failed_jobs table', 'tags' => ['laravel', 'queue', 'debug'], 'category' => 'troubleshooting'],
    ['content' => 'CI/CD workflow: push to feature branch -> PR -> review -> merge -> auto-deploy staging -> manual promote prod', 'tags' => ['ci', 'workflow'], 'category' => 'workflow'],
];

$knowledgeData = [
    ['content' => 'PHP 8.1 introduced enums, fibers, readonly properties, and intersection types', 'tags' => ['php', 'language'], 'source' => 'php.net'],
    ['content' => 'PostgreSQL JSONB indexes support GIN and GiST operators for efficient JSON queries', 'tags' => ['postgresql', 'json', 'performance'], 'source' => 'postgresql.org'],
    ['content' => 'EmbeddingGemma-300m outputs 768-dimensional vectors and supports 100+ languages', 'tags' => ['ai', 'embeddings'], 'source' => 'cloudflare-docs'],
    ['content' => 'Redis Cluster requires at least 6 nodes: 3 masters and 3 replicas for high availability', 'tags' => ['redis', 'cluster'], 'source' => 'redis.io'],
    ['content' => 'Laravel 11 removed service providers in favor of bootstrap/app.php configuration', 'tags' => ['laravel', 'framework'], 'source' => 'laravel.com'],
];

$searchQueries = [
    'What theme does the user prefer?' => 'dark mode',
    'How do I deploy to production?' => 'deploy',
    'What database are we using?' => 'PostgreSQL',
    'Tell me about PHP features' => 'PHP 8.1',
    'How to fix queue issues?' => 'Laravel queue',
    'What is the Redis configuration?' => 'Redis config',
    'User language preferences' => 'Spanish',
    'Embedding model details' => 'EmbeddingGemma',
];

// ── Setup ──────────────────────────────────────────────────────────────

$tmpDir = sys_get_temp_dir() . '/pam-benchmark-' . uniqid();
mkdir($tmpDir, 0755, true);

echo "\nPHPAgentMemory Benchmark — Cloudflare EmbeddingGemma-300m (768d)\n";
echo "Data dir: {$tmpDir}\n";
echo "Entities: " . count($memoryData) . " memories, " . count($skillData) . " skills, " . count($knowledgeData) . " knowledge\n";
echo "Queries: " . count($searchQueries) . "\n";

// =====================================================================
// BENCHMARK 1: Embedding Latency
// =====================================================================

printHeader('1. Embedding Latency (Cloudflare Workers AI)');

// Single embedding
[$vec, $singleMs] = timer(fn() => embedSingle('Hello world, this is a test'));
printResult('Single embed', $singleMs, count($vec) . 'd vector');

// Batch embedding (5 texts)
$batchTexts = array_column(array_slice($memoryData, 0, 5), 'content');
[$batchVecs, $batchMs] = timer(fn() => embed($batchTexts));
printResult('Batch embed (5)', $batchMs, number_format($batchMs / 5, 1) . 'ms/text');

// Batch embedding (all texts)
$allTexts = array_merge(
    array_column($memoryData, 'content'),
    array_column($skillData, 'content'),
    array_column($knowledgeData, 'content'),
);
[$allVecs, $allMs] = timer(fn() => embed($allTexts));
printResult('Batch embed (' . count($allTexts) . ')', $allMs, number_format($allMs / count($allTexts), 1) . 'ms/text');

// =====================================================================
// BENCHMARK 2: Save Performance
// =====================================================================

printHeader('2. Save Performance');

$memory = new AgentMemory(new Config(
    dataDir: $tmpDir,
    dimensions: DIMENSIONS,
    quantized: true,
));

$vecIndex = 0;

// Save memories
[$_, $memSaveMs] = timer(function () use ($memory, $memoryData, $allVecs, &$vecIndex) {
    foreach ($memoryData as $m) {
        $memory->memories->save('agent1', 'user1', $m, $allVecs[$vecIndex++]);
    }
});
printResult('Save ' . count($memoryData) . ' memories', $memSaveMs, number_format($memSaveMs / count($memoryData), 2) . 'ms/entity');

// Save skills
[$_, $skillSaveMs] = timer(function () use ($memory, $skillData, $allVecs, &$vecIndex) {
    foreach ($skillData as $s) {
        $memory->skills->save('agent1', null, $s, $allVecs[$vecIndex++]);
    }
});
printResult('Save ' . count($skillData) . ' skills', $skillSaveMs, number_format($skillSaveMs / count($skillData), 2) . 'ms/entity');

// Save knowledge
[$_, $knowSaveMs] = timer(function () use ($memory, $knowledgeData, $allVecs, &$vecIndex) {
    foreach ($knowledgeData as $k) {
        $memory->knowledge->save('agent1', null, $k, $allVecs[$vecIndex++]);
    }
});
printResult('Save ' . count($knowledgeData) . ' knowledge', $knowSaveMs, number_format($knowSaveMs / count($knowledgeData), 2) . 'ms/entity');

// Flush
[$_, $flushMs] = timer(fn() => $memory->flush());
printResult('Flush to disk', $flushMs);

$totalSave = $memSaveMs + $skillSaveMs + $knowSaveMs + $flushMs;
$totalEntities = count($memoryData) + count($skillData) + count($knowledgeData);
echo "\n  TOTAL: " . number_format($totalSave, 1) . "ms for {$totalEntities} entities\n";

// =====================================================================
// BENCHMARK 3: Search Accuracy
// =====================================================================

printHeader('3. Search Accuracy (Semantic Relevance)');

$queryTexts = array_keys($searchQueries);
$queryVecs = embed($queryTexts);

$hits = 0;
$totalQueries = count($searchQueries);
$searchTimes = [];

foreach (array_values($searchQueries) as $i => $expectedKeyword) {
    $query = $queryTexts[$i];
    $qvec = $queryVecs[$i];

    // Search memories
    [$results, $ms] = timer(fn() => $memory->memories->search('agent1', 'user1', $query, $qvec, 3));
    $searchTimes[] = $ms;

    // Also search skills and knowledge
    [$skillResults, $ms2] = timer(fn() => $memory->skills->searchWithShared('agent1', $query, $qvec, 3));
    $searchTimes[] = $ms2;

    [$knowResults, $ms3] = timer(fn() => $memory->knowledge->searchWithShared('agent1', $query, $qvec, 3));
    $searchTimes[] = $ms3;

    // Check if top result contains expected keyword
    $allResults = array_merge($results, $skillResults, $knowResults);
    usort($allResults, fn($a, $b) => $b['score'] <=> $a['score']);

    $topContent = $allResults[0]['metadata']['content'] ?? '';
    $found = stripos($topContent, $expectedKeyword) !== false;
    $hits += $found ? 1 : 0;

    $score = number_format($allResults[0]['score'] ?? 0, 4);
    $status = $found ? 'HIT' : 'MISS';
    $snippet = mb_substr($topContent, 0, 60);
    echo "  [{$status}] \"{$query}\"\n";
    echo "         → score={$score} \"{$snippet}...\"\n";
}

$accuracy = ($hits / $totalQueries) * 100;
$avgSearchMs = array_sum($searchTimes) / count($searchTimes);
echo "\n  Accuracy: {$hits}/{$totalQueries} (" . number_format($accuracy, 0) . "%)\n";
echo "  Avg search: " . number_format($avgSearchMs, 2) . "ms\n";

// =====================================================================
// BENCHMARK 4: Recall Quality (Multi-Collection)
// =====================================================================

printHeader('4. Recall Quality (Multi-Collection Pooling)');

$recallQueries = [
    'How should I configure and deploy the project?',
    'What are the user preferences and profile?',
    'Tell me about database setup and backup procedures',
];

foreach ($recallQueries as $rq) {
    $rvec = embedSingle($rq);

    [$context, $ms] = timer(fn() => $memory->recall('agent1', 'user1', $rq, $rvec, new RecallOptions(
        maxItems: 5,
    )));

    echo "\n  Query: \"{$rq}\"\n";
    echo "  Time: " . number_format($ms, 1) . "ms | Items: {$context->totalItems} (M:" . count($context->memories) . " S:" . count($context->skills) . " K:" . count($context->knowledge) . ")\n";

    $pool = array_merge(
        array_map(fn($r) => ['type' => 'MEM', 'score' => $r['score'], 'content' => $r['metadata']['content']], $context->memories),
        array_map(fn($r) => ['type' => 'SKL', 'score' => $r['score'], 'content' => $r['metadata']['content']], $context->skills),
        array_map(fn($r) => ['type' => 'KNW', 'score' => $r['score'], 'content' => $r['metadata']['content']], $context->knowledge),
    );
    usort($pool, fn($a, $b) => $b['score'] <=> $a['score']);

    foreach (array_slice($pool, 0, 5) as $j => $item) {
        $s = number_format($item['score'], 4);
        $snippet = mb_substr($item['content'], 0, 55);
        echo "    " . ($j + 1) . ". [{$item['type']}] score={$s} \"{$snippet}...\"\n";
    }
}

$memory->flush();

// =====================================================================
// BENCHMARK 4b: Matryoshka Progressive Search
// =====================================================================

printHeader('4b. Matryoshka Progressive Search (128→384→768d)');

$store = $memory->getStore();
$matryoshkaQuery = 'database configuration and backup';
$mqVec = embedSingle($matryoshkaQuery);

echo "  Query: \"{$matryoshkaQuery}\"\n\n";

// Brute force 768d
$memCol = 'mem-agent1-user1';
$skillCol = 'skill-agent1';
$knowCol = 'know-agent1';

// Search each method across all entity collections
$allCollections = [$memCol, $skillCol, $knowCol];

[$bruteResults, $bruteMs] = timer(function () use ($store, $allCollections, $mqVec) {
    return $store->searchAcross($allCollections, $mqVec, 5, 0); // full 768d
});
printResult('Brute force (768d)', $bruteMs, count($bruteResults) . ' results');
foreach (array_slice($bruteResults, 0, 3) as $j => $r) {
    $s = number_format($r['score'], 4);
    $snippet = mb_substr($r['metadata']['content'] ?? '', 0, 55);
    echo "    " . ($j + 1) . ". score={$s} \"{$snippet}...\"\n";
}

// Matryoshka 128→384→768
[$matryResults, $matryMs] = timer(function () use ($store, $allCollections, $mqVec) {
    $merged = [];
    foreach ($allCollections as $col) {
        foreach ($store->matryoshkaSearch($col, $mqVec, 5, [128, 384, 768]) as $r) {
            $r['collection'] = $col;
            $key = $col . ':' . $r['id'];
            if (!isset($merged[$key]) || $r['score'] > $merged[$key]['score']) {
                $merged[$key] = $r;
            }
        }
    }
    $all = array_values($merged);
    usort($all, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($all, 0, 5);
});
echo "\n";
printResult('Matryoshka (128→384→768)', $matryMs, count($matryResults) . ' results');
foreach (array_slice($matryResults, 0, 3) as $j => $r) {
    $s = number_format($r['score'], 4);
    $snippet = mb_substr($r['metadata']['content'] ?? '', 0, 55);
    $stages = implode('→', array_map(fn($k, $v) => "{$k}d:" . number_format($v, 3), array_keys($r['stages']), $r['stages']));
    echo "    " . ($j + 1) . ". score={$s} stages=[{$stages}] \"{$snippet}...\"\n";
}

// Compare: slice at 128d only
[$slice128Results, $slice128Ms] = timer(function () use ($store, $allCollections, $mqVec) {
    return $store->searchAcross($allCollections, $mqVec, 5, 128);
});
echo "\n";
printResult('Slice 128d only', $slice128Ms, count($slice128Results) . ' results');

// Speedup
if ($bruteMs > 0) {
    $speedup = $bruteMs / max($matryMs, 0.01);
    echo "\n  Matryoshka speedup: " . number_format($speedup, 1) . "x vs brute force\n";
    echo "  128d-only speedup: " . number_format($bruteMs / max($slice128Ms, 0.01), 1) . "x vs brute force\n";
}

// Ranking consistency (do matryoshka and brute-force agree on top result?)
$bruteTop = $bruteResults[0]['id'] ?? '';
$matryTop = $matryResults[0]['id'] ?? '';
echo "  Top-1 agreement: " . ($bruteTop === $matryTop ? 'YES (same top result)' : "NO (brute={$bruteTop}, matry={$matryTop})") . "\n";

// =====================================================================
// BENCHMARK 5: Dedup Detection
// =====================================================================

printHeader('5. Dedup Detection');

$originalContent = 'The user prefers dark mode for all applications';
$variantContent = 'User likes dark theme in every application they use';

$origVec = embedSingle($originalContent);
$variantVec = embedSingle($variantContent);

$rawSim = cosineSim($origVec, $variantVec);
echo "  Raw cosine similarity: " . number_format($rawSim, 4) . "\n";

$beforeCount = $memory->memories->count('agent1', 'user1');

[$dedupResult, $dedupMs] = timer(fn() => $memory->memories->saveOrUpdate('agent1', 'user1', [
    'content' => $variantContent,
    'tags' => ['preference', 'ui'],
    'category' => 'fact',
], $variantVec, 0.05));

$afterCount = $memory->memories->count('agent1', 'user1');
$deduped = $dedupResult['deduplicated'] ? 'YES' : 'NO';

echo "  Dedup triggered: {$deduped}\n";
echo "  Before: {$beforeCount} entities, After: {$afterCount} entities\n";
echo "  Time: " . number_format($dedupMs, 1) . "ms\n";

$memory->flush();

// =====================================================================
// BENCHMARK 6: Persistence Roundtrip
// =====================================================================

printHeader('6. Persistence Roundtrip');

$entityCount = $memory->memories->count('agent1', 'user1');
echo "  Entities before reload: {$entityCount}\n";

// Create new instance from same directory
[$memory2, $loadMs] = timer(fn() => new AgentMemory(new Config(
    dataDir: $tmpDir,
    dimensions: DIMENSIONS,
    quantized: true,
)));

$entityCount2 = $memory2->memories->count('agent1', 'user1');
echo "  Entities after reload: {$entityCount2}\n";
echo "  Load time: " . number_format($loadMs, 1) . "ms\n";
echo "  Data integrity: " . ($entityCount === $entityCount2 ? 'OK' : 'FAIL') . "\n";

// Test search after reload
$testVec = embedSingle('user preferences');
[$results2, $searchMs2] = timer(fn() => $memory2->memories->search('agent1', 'user1', 'user preferences', $testVec, 3));
echo "  Search after reload: " . count($results2) . " results in " . number_format($searchMs2, 1) . "ms\n";

// =====================================================================
// BENCHMARK 7: Storage Stats
// =====================================================================

printHeader('7. Storage Stats');

$stats = $memory->stats();
$vs = $stats['vectorStore'];
echo "  Dimensions: {$stats['dimensions']}d (quantized: " . ($stats['quantized'] ? 'Int8' : 'Float32') . ")\n";
echo "  Total vectors: {$vs['total_vectors']}\n";
echo "  Total storage: " . number_format($vs['total_bytes'] / 1024, 1) . " KB ({$vs['memory_mb']} MB)\n";
echo "  Bytes/vector: {$vs['bytes_per_vec']}\n";
echo "  Collections: " . count($vs['collections']) . "\n";
foreach ($vs['collections'] as $c) {
    echo "    - {$c['collection']}: {$c['vectors']} vectors, " . number_format($c['bytes'] / 1024, 1) . " KB\n";
}

// =====================================================================
// Summary
// =====================================================================

printHeader('SUMMARY');

echo "  Model: EmbeddingGemma-300m (768d, Cloudflare Workers AI)\n";
echo "  Storage: Int8 quantized (" . $vs['bytes_per_vec'] . " bytes/vector)\n";
echo "  Entities: {$totalEntities} total ({$entityCount} memories, " . count($skillData) . " skills, " . count($knowledgeData) . " knowledge)\n";
echo "  Embedding: " . number_format($allMs / count($allTexts), 1) . "ms/text (batch)\n";
echo "  Save: " . number_format($totalSave / $totalEntities, 2) . "ms/entity (avg)\n";
echo "  Search: " . number_format($avgSearchMs, 2) . "ms/query (hybrid RRF)\n";
echo "  Accuracy: " . number_format($accuracy, 0) . "% (top-1 hit rate)\n";
echo "  Dedup: " . ($dedupResult['deduplicated'] ? 'Working' : 'Not triggered') . " (cosine=" . number_format($rawSim, 4) . ")\n";
echo "  Persistence: " . ($entityCount === $entityCount2 ? 'Verified' : 'Failed') . "\n";
echo "  Storage: " . number_format($vs['total_bytes'] / 1024, 1) . " KB total\n";

// Cleanup
cleanDir($tmpDir);
echo "\nBenchmark complete. Temp data cleaned.\n";
