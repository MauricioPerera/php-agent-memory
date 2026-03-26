<?php

declare(strict_types=1);

namespace PHPAgentMemory\Tests;

use PHPUnit\Framework\TestCase;
use PHPAgentMemory\IdGenerator;
use PHPAgentMemory\Entity\EntityType;

final class IdGeneratorTest extends TestCase
{
    public function testGenerateFormat(): void
    {
        $id = IdGenerator::generate(EntityType::Memory);
        $this->assertMatchesRegularExpression('/^memory-[a-z0-9]+-[a-f0-9]{8}$/', $id);
    }

    public function testGenerateSkillPrefix(): void
    {
        $id = IdGenerator::generate(EntityType::Skill);
        $this->assertStringStartsWith('skill-', $id);
    }

    public function testGenerateKnowledgePrefix(): void
    {
        $id = IdGenerator::generate(EntityType::Knowledge);
        $this->assertStringStartsWith('knowledge-', $id);
    }

    public function testUniqueness(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = IdGenerator::generate(EntityType::Memory);
        }
        $this->assertCount(100, array_unique($ids));
    }
}
