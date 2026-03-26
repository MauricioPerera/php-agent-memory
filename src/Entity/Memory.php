<?php

declare(strict_types=1);

namespace PHPAgentMemory\Entity;

final readonly class Memory
{
    public function __construct(
        public string $id,
        public string $agentId,
        public string $userId,
        public string $content,
        public array $tags,
        public MemoryCategory $category,
        public int $accessCount = 0,
        public string $createdAt = '',
        public string $updatedAt = '',
        public bool $deleted = false,
        public ?string $sourceSession = null,
    ) {}

    public static function fromMetadata(string $id, array $meta): self
    {
        return new self(
            id: $id,
            agentId: $meta['agentId'] ?? '',
            userId: $meta['userId'] ?? '',
            content: $meta['content'] ?? '',
            tags: $meta['tags'] ?? [],
            category: MemoryCategory::tryFrom($meta['category'] ?? 'fact') ?? MemoryCategory::Fact,
            accessCount: (int) ($meta['accessCount'] ?? 0),
            createdAt: $meta['createdAt'] ?? '',
            updatedAt: $meta['updatedAt'] ?? '',
            deleted: (bool) ($meta['deleted'] ?? false),
            sourceSession: $meta['sourceSession'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => EntityType::Memory->value,
            'agentId' => $this->agentId,
            'userId' => $this->userId,
            'content' => $this->content,
            'tags' => $this->tags,
            'category' => $this->category->value,
            'accessCount' => $this->accessCount,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'deleted' => $this->deleted,
            'sourceSession' => $this->sourceSession,
        ];
    }
}
