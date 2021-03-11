<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer\Mutators;

use PhpParser\Comment\Doc;
use PhpParser\Node;

use function class_exists;

final class ExtractImportsFromMethodDocBlock
{
    /**
     * @return array<string, string>
     */
    public static function extract(Node\Stmt\ClassMethod $method, string $namespace): array
    {
        $docblock = $method->getDocComment();
        if (! ($docblock instanceof Doc)) {
            return [];
        }

        $type = ParseReturnTypeFromDocBlock::parse($method);
        if ($type === null) {
            return [];
        }

        if (class_exists($namespace . '\\' . (string) $type)) {
            return [(string) $type => $namespace . '\\' . (string) $type];
        }

        return [];
    }
}
