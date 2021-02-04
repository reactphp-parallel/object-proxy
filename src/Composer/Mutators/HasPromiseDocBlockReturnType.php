<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer\Mutators;

use PhpParser\Node;

use function Safe\substr;

final class HasPromiseDocBlockReturnType
{
    public static function has(Node\Stmt\ClassMethod $method): bool
    {
        $type = ParseReturnTypeFromDocBlock::parse($method);
        if ($type === null) {
            return false;
        }

//        $type = $type->type;
//        if ($type === null) {
//            return false;
//        }

        return substr((string) $type, 0, 16) === 'PromiseInterface';
    }
}
