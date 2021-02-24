<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer\Mutators;

use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;

use function count;
use function current;

final class ParseReturnTypeFromDocBlock
{
    /** @phpstan-ignore-next-line  */
    public static function parse(Node\Stmt\ClassMethod $method): ?ReturnTagValueNode
    {
        $docblock = $method->getDocComment();

        if ($docblock === null) {
            return null;
        }

        $constExprParser = new ConstExprParser();
        $tokens          = new TokenIterator((new Lexer())->tokenize($docblock->getText()));
        $returnTypes     = (new PhpDocParser(new TypeParser($constExprParser), $constExprParser))->parse($tokens)->getReturnTagValues();

        if (count($returnTypes) === 0) {
            return null;
        }

        return current($returnTypes);
    }
}
