<?php

declare(strict_types=1);

namespace PHPAgentMemory;

use PHPAgentMemory\Entity\EntityType;

final class Scoping
{
    public const SHARED_AGENT_ID = '_shared';

    public static function collectionName(EntityType $type, string $agentId, ?string $userId = null): string
    {
        return match ($type) {
            EntityType::Memory => "mem-{$agentId}-{$userId}",
            EntityType::Skill => "skill-{$agentId}",
            EntityType::Knowledge => "know-{$agentId}",
        };
    }

    public static function memoryCollection(string $agentId, string $userId): string
    {
        return "mem-{$agentId}-{$userId}";
    }

    public static function skillCollection(string $agentId): string
    {
        return "skill-{$agentId}";
    }

    public static function knowledgeCollection(string $agentId): string
    {
        return "know-{$agentId}";
    }

    public static function sharedSkillCollection(): string
    {
        return 'skill-' . self::SHARED_AGENT_ID;
    }

    public static function sharedKnowledgeCollection(): string
    {
        return 'know-' . self::SHARED_AGENT_ID;
    }
}
