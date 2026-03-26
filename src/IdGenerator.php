<?php

declare(strict_types=1);

namespace PHPAgentMemory;

use PHPAgentMemory\Entity\EntityType;

final class IdGenerator
{
    public static function generate(EntityType $type): string
    {
        $time = base_convert((string) intval(microtime(true) * 1000), 10, 36);
        $rand = bin2hex(random_bytes(4));

        return "{$type->value}-{$time}-{$rand}";
    }
}
