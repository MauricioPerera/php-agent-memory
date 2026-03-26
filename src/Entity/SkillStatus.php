<?php

declare(strict_types=1);

namespace PHPAgentMemory\Entity;

enum SkillStatus: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Draft = 'draft';
}
