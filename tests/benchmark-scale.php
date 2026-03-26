<?php

/**
 * PHPAgentMemory Scale Benchmark — AG News dataset + Cloudflare EmbeddingGemma-300m
 *
 * Tests at 100, 500, 1000, 2000 vectors to measure how performance scales.
 * Uses Matryoshka progressive search (128→384→768d).
 *
 * Usage: php tests/benchmark-scale.php [--size 1000]
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Config;
use PHPAgentMemory\Recall\RecallOptions;

// ── Config ─────────────────────────────────────────────────────────────

$CF_ACCOUNT_ID = getenv('CF_ACCOUNT_ID') ?: die("Set CF_ACCOUNT_ID env var\n");
$CF_API_TOKEN  = getenv('CF_API_TOKEN') ?: die("Set CF_API_TOKEN env var\n");
define('CF_ACCOUNT_ID', $CF_ACCOUNT_ID);
define('CF_API_TOKEN', $CF_API_TOKEN);
const CF_MODEL      = '@cf/google/embeddinggemma-300m';
const DIMENSIONS    = 768;
const BATCH_SIZE    = 100; // Cloudflare max per request
const CATEGORIES    = ['World', 'Sports', 'Business', 'Sci/Tech']; // AG News labels

// ── Helpers ─────────────────────────────────────────────────────────────

function embedBatch(array $texts): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.cloudflare.com/client/v4/accounts/' . CF_ACCOUNT_ID . '/ai/run/' . CF_MODEL,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . CF_API_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['text' => $texts]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if (!($data['success'] ?? false) || empty($data['result']['data'])) {
        throw new RuntimeException("Embedding failed (HTTP {$code}): " . json_encode($data['errors'] ?? []));
    }
    return $data['result']['data'];
}

function fetchAgNews(int $count): array
{
    $rows = [];
    $offset = 0;
    $pageSize = min($count, 100);

    while (count($rows) < $count) {
        $split = $count > 7000 ? 'train' : 'test'; // train has 120K rows
        $url = "https://datasets-server.huggingface.co/rows?dataset=fancyzhx/ag_news&config=default&split={$split}&offset={$offset}&length={$pageSize}";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $pageRows = $data['rows'] ?? [];

        if (empty($pageRows)) break;

        foreach ($pageRows as $r) {
            $row = $r['row'] ?? [];
            $rows[] = [
                'text' => $row['text'] ?? '',
                'label' => (int) ($row['label'] ?? 0),
            ];
            if (count($rows) >= $count) break;
        }
        $offset += $pageSize;
    }

    return $rows;
}

function timer(callable $fn): array
{
    $start = hrtime(true);
    $result = $fn();
    $ms = (hrtime(true) - $start) / 1_000_000;
    return [$result, $ms];
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

function printBar(string $label, float $value, float $max, string $unit = 'ms'): void
{
    $pct = $max > 0 ? $value / $max : 0;
    $barLen = (int) ($pct * 30);
    $bar = str_repeat('█', $barLen) . str_repeat('░', 30 - $barLen);
    $val = number_format($value, 1);
    printf("  %-12s %s %s%s\n", $label, $bar, $val, $unit);
}

// ── Parse args ──────────────────────────────────────────────────────────

$targetSize = 1000;
foreach (array_slice($argv ?? [], 1) as $i => $arg) {
    if ($arg === '--size' && isset($argv[$i + 2])) {
        $targetSize = (int) $argv[$i + 2];
    }
}

$testSizes = [100, 500];
if ($targetSize >= 1000) $testSizes[] = 1000;
if ($targetSize >= 2000) $testSizes[] = 2000;
if ($targetSize >= 5000) $testSizes[] = 5000;

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
echo "║  PHPAgentMemory SCALE BENCHMARK                            ║\n";
echo "║  Dataset: AG News (HuggingFace) + EmbeddingGemma-300m     ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// ── Step 1: Fetch dataset ───────────────────────────────────────────────

echo "1. Fetching AG News dataset ({$targetSize} rows)...\n";
[$rawData, $fetchMs] = timer(fn() => fetchAgNews($targetSize));
echo "   Fetched " . count($rawData) . " rows in " . number_format($fetchMs / 1000, 1) . "s\n";

// ── Step 2: Generate embeddings ─────────────────────────────────────────

echo "\n2. Generating embeddings (batches of " . BATCH_SIZE . ")...\n";

$allTexts = array_column($rawData, 'text');
$allVectors = [];
$embedStartMs = hrtime(true);
$batchCount = 0;

foreach (array_chunk($allTexts, BATCH_SIZE) as $chunk) {
    $batchCount++;
    $vectors = embedBatch($chunk);
    $allVectors = array_merge($allVectors, $vectors);
    echo "   Batch {$batchCount}/" . ceil(count($allTexts) / BATCH_SIZE) . " — " . count($allVectors) . "/" . count($allTexts) . " vectors\n";
}

$embedTotalMs = (hrtime(true) - $embedStartMs) / 1_000_000;
$embedPerText = $embedTotalMs / count($allTexts);
echo "   Total: " . number_format($embedTotalMs / 1000, 1) . "s (" . number_format($embedPerText, 1) . "ms/text)\n";

// ── Step 3: Run benchmarks at each scale ────────────────────────────────

echo "\n3. Running benchmarks at scale points...\n";

$results = [];

foreach ($testSizes as $size) {
    echo "\n" . str_repeat('─', 60) . "\n";
    echo "  SCALE: {$size} vectors\n";
    echo str_repeat('─', 60) . "\n";

    $tmpDir = sys_get_temp_dir() . '/pam-scale-' . $size . '-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $memory = new AgentMemory(new Config(
        dataDir: $tmpDir,
        dimensions: DIMENSIONS,
        quantized: true,
    ));

    // ── Save ────────────────────────────────────────────────────────
    $subset = array_slice($rawData, 0, $size);
    $subVecs = array_slice($allVectors, 0, $size);

    [$_, $saveMs] = timer(function () use ($memory, $subset, $subVecs) {
        foreach ($subset as $i => $row) {
            $cat = CATEGORIES[$row['label']] ?? 'World';
            $catLower = strtolower($cat);

            // Distribute: 60% memories, 20% skills, 20% knowledge
            if ($i % 5 < 3) {
                $memory->memories->save('agent1', 'user1', [
                    'content' => $row['text'],
                    'tags' => [$catLower],
                    'category' => 'fact',
                ], $subVecs[$i]);
            } elseif ($i % 5 === 3) {
                $memory->skills->save('agent1', null, [
                    'content' => $row['text'],
                    'tags' => [$catLower],
                    'category' => 'procedure',
                ], $subVecs[$i]);
            } else {
                $memory->knowledge->save('agent1', null, [
                    'content' => $row['text'],
                    'tags' => [$catLower],
                    'source' => 'ag-news',
                ], $subVecs[$i]);
            }
        }
    });

    [$_, $flushMs] = timer(fn() => $memory->flush());

    echo "  Save: " . number_format($saveMs, 0) . "ms (" . number_format($saveMs / $size, 2) . "ms/entity)\n";
    echo "  Flush: " . number_format($flushMs, 0) . "ms\n";

    // ── Search queries ──────────────────────────────────────────────
    $queries = [
        'stock market financial crisis',
        'football soccer championship game',
        'space exploration NASA rocket launch',
        'presidential election political campaign',
        'technology artificial intelligence machine learning',
    ];

    // Embed queries once
    $queryVecs = embedBatch($queries);

    // Brute force search
    $store = $memory->getStore();
    $collections = $store->collections();
    $bruteTimes = [];
    $bruteResults = [];

    foreach ($queries as $qi => $q) {
        [$res, $ms] = timer(fn() => $store->searchAcross($collections, $queryVecs[$qi], 10, 0));
        $bruteTimes[] = $ms;
        $bruteResults[$qi] = $res;
    }
    $avgBrute = array_sum($bruteTimes) / count($bruteTimes);

    // Matryoshka 128→384→768
    $matryTimes = [];
    $matryResults = [];

    foreach ($queries as $qi => $q) {
        [$res, $ms] = timer(function () use ($store, $collections, $queryVecs, $qi) {
            $merged = [];
            foreach ($collections as $col) {
                foreach ($store->matryoshkaSearch($col, $queryVecs[$qi], 10, [128, 384, 768]) as $r) {
                    $key = $col . ':' . $r['id'];
                    if (!isset($merged[$key]) || $r['score'] > $merged[$key]['score']) {
                        $r['collection'] = $col;
                        $merged[$key] = $r;
                    }
                }
            }
            $all = array_values($merged);
            usort($all, fn($a, $b) => $b['score'] <=> $a['score']);
            return array_slice($all, 0, 10);
        });
        $matryTimes[] = $ms;
        $matryResults[$qi] = $res;
    }
    $avgMatry = array_sum($matryTimes) / count($matryTimes);

    // Matryoshka 128 only (fastest)
    $slice128Times = [];
    foreach ($queries as $qi => $q) {
        [$_, $ms] = timer(fn() => $store->searchAcross($collections, $queryVecs[$qi], 10, 128));
        $slice128Times[] = $ms;
    }
    $avgSlice128 = array_sum($slice128Times) / count($slice128Times);

    // Hybrid RRF search
    $hybridTimes = [];
    foreach ($queries as $qi => $q) {
        [$_, $ms] = timer(fn() => $memory->recall('agent1', 'user1', $q, $queryVecs[$qi], new RecallOptions(maxItems: 10)));
        $hybridTimes[] = $ms;
    }
    $avgHybrid = array_sum($hybridTimes) / count($hybridTimes);

    // Top-1 agreement between brute and matryoshka
    $agreements = 0;
    foreach ($queries as $qi => $q) {
        $bt = $bruteResults[$qi][0]['id'] ?? '';
        $mt = $matryResults[$qi][0]['id'] ?? '';
        if ($bt === $mt) $agreements++;
    }

    $maxSearch = max($avgBrute, $avgMatry, $avgSlice128, $avgHybrid);
    echo "\n  Search (avg of " . count($queries) . " queries):\n";
    printBar('Brute 768d', $avgBrute, $maxSearch);
    printBar('Matry 128→768', $avgMatry, $maxSearch);
    printBar('Slice 128d', $avgSlice128, $maxSearch);
    printBar('Hybrid RRF', $avgHybrid, $maxSearch);

    $speedupMatry = $avgBrute / max($avgMatry, 0.01);
    $speedup128 = $avgBrute / max($avgSlice128, 0.01);
    echo "\n  Matryoshka speedup: " . number_format($speedupMatry, 1) . "x vs brute\n";
    echo "  128d-only speedup: " . number_format($speedup128, 1) . "x vs brute\n";
    echo "  Top-1 agreement: {$agreements}/" . count($queries) . " (" . (int)(($agreements / count($queries)) * 100) . "%)\n";

    // Show top result for first query
    $topRes = $matryResults[0][0] ?? null;
    if ($topRes) {
        $snippet = mb_substr($topRes['metadata']['content'] ?? '', 0, 70);
        $stages = implode('→', array_map(
            fn($k, $v) => "{$k}d:" . number_format($v, 3),
            array_keys($topRes['stages'] ?? []),
            $topRes['stages'] ?? [],
        ));
        echo "\n  Example: \"{$queries[0]}\"\n";
        echo "    → [{$stages}] \"{$snippet}...\"\n";
    }

    // Storage
    $stats = $memory->stats();
    $vs = $stats['vectorStore'];
    $kb = $vs['total_bytes'] / 1024;
    echo "\n  Storage: " . number_format($kb, 0) . " KB (" . number_format($kb / $size, 2) . " KB/vector)\n";

    $results[] = [
        'size' => $size,
        'saveMs' => $saveMs,
        'flushMs' => $flushMs,
        'bruteMs' => $avgBrute,
        'matryMs' => $avgMatry,
        'slice128Ms' => $avgSlice128,
        'hybridMs' => $avgHybrid,
        'speedupMatry' => $speedupMatry,
        'speedup128' => $speedup128,
        'agreement' => $agreements,
        'storageKb' => $kb,
    ];

    $memory->flush();
    cleanDir($tmpDir);
}

// ── Summary Table ───────────────────────────────────────────────────────

echo "\n\n" . str_repeat('═', 60) . "\n";
echo "  SCALE COMPARISON TABLE\n";
echo str_repeat('═', 60) . "\n\n";

printf("  %-8s │ %8s │ %8s │ %8s │ %8s │ %6s │ %7s\n",
    'Vectors', 'Brute', 'Matry', '128d', 'Hybrid', 'Speed', 'Storage');
echo "  " . str_repeat('─', 67) . "\n";

foreach ($results as $r) {
    printf("  %-8s │ %6.1fms │ %6.1fms │ %6.1fms │ %6.1fms │ %5.1fx │ %5.0f KB\n",
        number_format($r['size']),
        $r['bruteMs'],
        $r['matryMs'],
        $r['slice128Ms'],
        $r['hybridMs'],
        $r['speedupMatry'],
        $r['storageKb'],
    );
}

echo "\n  Model: EmbeddingGemma-300m (768d, Matryoshka, Int8 quantized)\n";
echo "  Embedding: " . number_format($embedPerText, 1) . "ms/text (Cloudflare Workers AI batch)\n";
echo "  Dataset: AG News (4 categories: World, Sports, Business, Sci/Tech)\n";

echo "\n  Benchmark complete.\n";
