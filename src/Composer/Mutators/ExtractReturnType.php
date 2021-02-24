<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer\Mutators;

use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;

use function current;
use function property_exists;

final class ExtractReturnType
{
    /** @phpstan-ignore-next-line  */
    public static function extract(Node\Stmt\ClassMethod $method): ?Node\Identifier
    {
        $type = ParseReturnTypeFromDocBlock::parse($method);

        if ($type === null) {
            return null;
        }

        $genericType = $type->type;
        if (! property_exists($genericType, 'genericTypes')) {
            return null;
        }

        /**
         * @psalm-suppress NoInterfaceProperties
         */
        $type = (string) current($genericType->genericTypes);

        if (current($genericType->genericTypes) instanceof GenericTypeNode) {
            $type = (string) current($genericType->genericTypes)->type;
        }

        if ($type === 'mixed') {
            return null;
        }

        return new Node\Identifier($type);
    }
}
