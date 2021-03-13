<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer\Mutators;

use PhpParser\Node;

use function Safe\substr;

final class HasObservableDocBlockReturnType
{
    public static function has(Node\Stmt\ClassMethod $method): bool
    {
        $type = ParseReturnTypeFromDocBlock::parse($method);
        if ($type === null) {
            return false;
        }

        return substr((string) $type->type, 0, 10) === 'Observable';
    }
}
