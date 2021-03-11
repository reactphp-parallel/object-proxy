<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer\Mutators;

use PhpParser\Node;

final class CreateUseUse
{
    /**
     * @phpstan-ignore-next-line
     */
    public static function create(string $fqcn, ?string $alias): Node\Stmt\UseUse
    {
        return new Node\Stmt\UseUse(new Node\Name($fqcn), $alias);
    }
}
