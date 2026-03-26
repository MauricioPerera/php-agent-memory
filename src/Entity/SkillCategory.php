<?php

declare(strict_types=1);

namespace PHPAgentMemory\Entity;

enum SkillCategory: string
{
    case Procedure = 'procedure';
    case Configuration = 'configuration';
    case Troubleshooting = 'troubleshooting';
    case Workflow = 'workflow';
}
