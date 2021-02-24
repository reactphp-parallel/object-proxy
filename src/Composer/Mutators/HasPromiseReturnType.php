<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer\Mutators;

use PhpParser\Node;

final class HasPromiseReturnType
{
    public static function has(Node\Stmt\ClassMethod $method): bool
    {
        $returnType = $method->getReturnType();

        if ($returnType === null) {
            return false;
        }

        if (! ($returnType instanceof Node\Name)) {
            return false;
        }

        /**
         * @todo Use FQCN PromiseInterface::class
         */
        return (string) $returnType === 'PromiseInterface';
    }
}
