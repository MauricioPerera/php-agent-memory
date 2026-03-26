<?php

declare(strict_types=1);

namespace PHPAgentMemory;

final class AccessTracker
{
    /** @var array<string, int> */
    private array $counts = [];
    private bool $dirty = false;
    private readonly string $filePath;

    public function __construct(string $dataDir)
    {
        $this->filePath = rtrim($dataDir, '/\\') . '/access-counts.json';
        $this->load();
    }

    public function increment(string $id): void
    {
        $this->counts[$id] = ($this->counts[$id] ?? 0) + 1;
        $this->dirty = true;
    }

    /** @param string[] $ids */
    public function incrementMany(array $ids): void
    {
        foreach ($ids as $id) {
            $this->increment($id);
        }
    }

    public function get(string $id): int
    {
        return $this->counts[$id] ?? 0;
    }

    public function flush(): void
    {
        if (!$this->dirty) {
            return;
        }

        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmp = $this->filePath . '.tmp';
        file_put_contents($tmp, json_encode($this->counts, JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->filePath);
        $this->dirty = false;
    }

    private function load(): void
    {
        if (!file_exists($this->filePath)) {
            return;
        }

        $data = json_decode(file_get_contents($this->filePath), true);
        if (is_array($data)) {
            $this->counts = $data;
        }
    }
}
