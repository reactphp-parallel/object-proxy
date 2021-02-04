<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer\Mutators;

use PhpParser\Node;

use function in_array;

final class HasObservableReturnType
{
    public static function has(Node\Stmt\ClassMethod $method): bool
    {
        $returnType = $method->getReturnType();

        if (! ($returnType instanceof Node\Name)) {
            return false;
        }

        /**
         * @todo Use FQCN ObservableInterface::class
         * @todo Use FQCN Observable::class
         */
        return in_array((string) $returnType, ['ObservableInterface', 'Observable'], true);
    }
}
