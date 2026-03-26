<?php

declare(strict_types=1);

namespace PHPAgentMemory\Entity;

final readonly class Knowledge
{
    public function __construct(
        public string $id,
        public string $agentId,
        public string $content,
        public array $tags,
        public ?string $source = null,
        public int $accessCount = 0,
        public string $createdAt = '',
        public string $updatedAt = '',
        public bool $deleted = false,
    ) {}

    public static function fromMetadata(string $id, array $meta): self
    {
        return new self(
            id: $id,
            agentId: $meta['agentId'] ?? '',
            content: $meta['content'] ?? '',
            tags: $meta['tags'] ?? [],
            source: $meta['source'] ?? null,
            accessCount: (int) ($meta['accessCount'] ?? 0),
            createdAt: $meta['createdAt'] ?? '',
            updatedAt: $meta['updatedAt'] ?? '',
            deleted: (bool) ($meta['deleted'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => EntityType::Knowledge->value,
            'agentId' => $this->agentId,
            'content' => $this->content,
            'tags' => $this->tags,
            'source' => $this->source,
            'accessCount' => $this->accessCount,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'deleted' => $this->deleted,
        ];
    }
}
