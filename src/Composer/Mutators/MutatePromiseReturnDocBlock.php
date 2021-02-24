<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer\Mutators;

use PhpParser\Comment\Doc;
use PhpParser\Node;

use function str_replace;

use const PHP_EOL;

final class MutatePromiseReturnDocBlock
{
    public static function mutate(Node\Stmt\ClassMethod $method): void
    {
        $docblock = $method->getDocComment();
        if (! ($docblock instanceof Doc)) {
            return;
        }

        $method->setDocComment(
            new Doc(
                str_replace(['@return PromiseInterface<', '> ', '>' . PHP_EOL], ['@return ', ' ', PHP_EOL], $docblock->getText()),
                $docblock->getStartLine(),
                $docblock->getStartFilePos(),
                $docblock->getStartTokenPos(),
                $docblock->getEndLine(),
                $docblock->getEndFilePos(),
                $docblock->getEndTokenPos(),
            )
        );
    }
}
