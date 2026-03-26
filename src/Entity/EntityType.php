<?php

declare(strict_types=1);

namespace PHPAgentMemory\Entity;

enum EntityType: string
{
    case Memory = 'memory';
    case Skill = 'skill';
    case Knowledge = 'knowledge';
}
