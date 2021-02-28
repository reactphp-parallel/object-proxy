<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer\Mutators;

use PhpParser\Node;

final class CreateUses
{
    /**
     * @param array<string, string> $uses
     *
     * @return array<Node\Stmt\UseUse>
     */
    public static function create(array $uses): array
    {
        /** @var Node\Stmt\UseUse[] $stmts */
        $stmts = [];

        foreach ($uses as $alias => $fqcn) {
            $stmts[] = CreateUseUse::create($fqcn, $alias);
        }

        return $stmts;
    }
}
