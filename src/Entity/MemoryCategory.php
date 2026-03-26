<?php

declare(strict_types=1);

namespace PHPAgentMemory\Entity;

enum MemoryCategory: string
{
    case Fact = 'fact';
    case Decision = 'decision';
    case Issue = 'issue';
    case Task = 'task';
    case Correction = 'correction';
}
