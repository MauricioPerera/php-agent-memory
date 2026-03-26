<?php

/**
 * Dream Demo — Watch the agent "sleep" and consolidate its memories.
 *
 * Uses Cloudflare Workers AI:
 *   - EmbeddingGemma-300m for vectors
 *   - Granite 4.0 H-Micro for consolidation LLM
 *
 * Usage: php tests/demo-dream.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPAgentMemory\AgentMemory;
use PHPAgentMemory\Config;
use PHPAgentMemory\Consolidation\CloudflareLlmProvider;
use PHPAgentMemory\Dream\DreamPipeline;

$CF_ACCOUNT = getenv('CF_ACCOUNT_ID') ?: die("Set CF_ACCOUNT_ID env var\n");
$CF_TOKEN   = getenv('CF_API_TOKEN') ?: die("Set CF_API_TOKEN env var\n");
define('CF_ACCOUNT', $CF_ACCOUNT);
define('CF_TOKEN', $CF_TOKEN);
const DIMS       = 768;

// ── Embedding helper ────────────────────────────────────────────────

function cfEmbed(string $text): array
{
    $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/" . CF_ACCOUNT . "/ai/run/@cf/google/embeddinggemma-300m");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . CF_TOKEN, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text' => [$text]]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $data['result']['data'][0] ?? [];
}

function cfEmbedBatch(array $texts): array
{
    $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/" . CF_ACCOUNT . "/ai/run/@cf/google/embeddinggemma-300m");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . CF_TOKEN, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $texts]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $data['result']['data'] ?? [];
}

// ── Setup ───────────────────────────────────────────────────────────

$tmpDir = sys_get_temp_dir() . '/pam-dream-' . uniqid();
mkdir($tmpDir, 0755, true);

$llm = new CloudflareLlmProvider(CF_ACCOUNT, CF_TOKEN);

$memory = new AgentMemory(new Config(
    dataDir: $tmpDir,
    dimensions: DIMS,
    quantized: true,
    llmProvider: $llm,
));

echo "\n";
echo "  ┌──────────────────────────────────────────────┐\n";
echo "  │        DREAM DEMO — Agent Memory Sleep        │\n";
echo "  │  EmbeddingGemma-300m + Granite 4.0 H-Micro   │\n";
echo "  └──────────────────────────────────────────────┘\n\n";

// ── Phase 1: Simulate a day of agent activity ───────────────────────

echo "  AWAKE: Simulating agent activity...\n\n";

// Intentionally include duplicates and contradictions
$dayMemories = [
    // Duplicate cluster 1: user preferences
    'The user prefers dark mode for coding',
    'User likes dark theme in all applications',
    'Dark mode is the preferred theme for the user',

    // Duplicate cluster 2: deployment info
    'Deploy to production by running: git push origin main',
    'Production deployment: push to main branch, then SSH and run deploy.sh',
    'To deploy: git push origin main and execute the deployment script',

    // Duplicate cluster 3: database choice
    'The project uses PostgreSQL 16 as the primary database',
    'PostgreSQL is the database engine for this project',

    // Unique memories
    'The user speaks Spanish and English fluently',
    'API rate limit was increased to 2000 req/min after the scaling incident',
    'User timezone is America/Mexico_City (UTC-6)',
    'The authentication module needs refactoring before Q2 launch',
    'Redis is configured with maxmemory 512mb and allkeys-lru eviction policy',

    // Stale/contradicted info
    'The API rate limit is 500 req/min',  // contradicted by the correction above
];

$daySkills = [
    // Duplicate pair
    'To backup the database: pg_dump -Fc mydb > backup.dump',
    'Database backup procedure: run pg_dump with custom format to create dump file',

    // Unique
    'When Laravel queue fails: check Redis, restart Horizon, inspect failed_jobs table',
    'CI/CD: push feature branch -> PR -> review -> merge -> auto-deploy staging',
];

echo "  Embedding " . (count($dayMemories) + count($daySkills)) . " entities...\n";

$allTexts = array_merge($dayMemories, $daySkills);
$allVecs = cfEmbedBatch($allTexts);
$vi = 0;

echo "  Saving memories...\n";
foreach ($dayMemories as $m) {
    $memory->memories->save('agent1', 'user1', [
        'content' => $m,
        'tags' => ['day-activity'],
        'category' => 'fact',
    ], $allVecs[$vi++]);
}

echo "  Saving skills...\n";
foreach ($daySkills as $s) {
    $memory->skills->save('agent1', null, [
        'content' => $s,
        'tags' => ['day-activity'],
        'category' => 'procedure',
    ], $allVecs[$vi++]);
}

$memory->flush();

$preMemories = $memory->memories->count('agent1', 'user1');
$preSkills = $memory->skills->count('agent1');
echo "\n  Before sleep: {$preMemories} memories, {$preSkills} skills\n";

// ── Phase 2: The Dream ──────────────────────────────────────────────

echo "\n  SLEEPING... zzz\n\n";

$dream = new DreamPipeline($memory, $llm, fn(string $text) => cfEmbed($text));

$report = $dream->run('agent1', 'user1');

// Print the dream log
echo (string) $report . "\n\n";

// ── Phase 3: Verify what survived ───────────────────────────────────

echo "  AWAKE: Checking what survived the dream...\n\n";

$postMemories = $memory->memories->count('agent1', 'user1');
$postSkills = $memory->skills->count('agent1');

echo "  After sleep: {$postMemories} memories, {$postSkills} skills\n";
echo "  Reduction: " . ($preMemories - $postMemories) . " memories, " . ($preSkills - $postSkills) . " skills\n\n";

// List surviving memories
echo "  Surviving memories:\n";
$entities = $memory->memories->listEntities('agent1', 'user1', 50);
foreach ($entities as $i => $e) {
    $snippet = mb_substr($e['content'], 0, 70);
    echo "    " . ($i + 1) . ". \"{$snippet}...\"\n";
}

echo "\n  Surviving skills:\n";
$skillEntities = $memory->skills->listEntities('agent1', null, 50);
foreach ($skillEntities as $i => $e) {
    $snippet = mb_substr($e['content'], 0, 70);
    echo "    " . ($i + 1) . ". \"{$snippet}...\"\n";
}

// ── Recall test after dream ─────────────────────────────────────────

echo "\n  Testing recall after dream...\n";
$queryVec = cfEmbed('How do I deploy the project?');
$context = $memory->recall('agent1', 'user1', 'How do I deploy the project?', $queryVec);
echo "\n  Recall for \"How do I deploy the project?\":\n";
echo "  " . str_replace("\n", "\n  ", $context->formatted) . "\n";

// Cleanup
echo "\n  Duration: " . number_format($report->getDurationMs(), 0) . "ms\n";

// Clean up
function cleanDir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? cleanDir($p) : unlink($p);
    }
    rmdir($dir);
}
cleanDir($tmpDir);

echo "  Temp data cleaned. Dream demo complete.\n\n";
