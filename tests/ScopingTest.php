<?php

declare(strict_types=1);

namespace PHPAgentMemory\Tests;

use PHPUnit\Framework\TestCase;
use PHPAgentMemory\Scoping;
use PHPAgentMemory\Entity\EntityType;

final class ScopingTest extends TestCase
{
    public function testMemoryCollection(): void
    {
        $this->assertSame('mem-agent1-user1', Scoping::memoryCollection('agent1', 'user1'));
    }

    public function testSkillCollection(): void
    {
        $this->assertSame('skill-agent1', Scoping::skillCollection('agent1'));
    }

    public function testKnowledgeCollection(): void
    {
        $this->assertSame('know-agent1', Scoping::knowledgeCollection('agent1'));
    }

    public function testSharedCollections(): void
    {
        $this->assertSame('skill-_shared', Scoping::sharedSkillCollection());
        $this->assertSame('know-_shared', Scoping::sharedKnowledgeCollection());
    }

    public function testCollectionNameDispatch(): void
    {
        $this->assertSame('mem-a-u', Scoping::collectionName(EntityType::Memory, 'a', 'u'));
        $this->assertSame('skill-a', Scoping::collectionName(EntityType::Skill, 'a'));
        $this->assertSame('know-a', Scoping::collectionName(EntityType::Knowledge, 'a'));
    }

    public function testSharedAgentId(): void
    {
        $this->assertSame('_shared', Scoping::SHARED_AGENT_ID);
    }
}
