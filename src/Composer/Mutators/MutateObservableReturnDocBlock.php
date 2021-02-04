<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer\Mutators;

use PhpParser\Comment\Doc;
use PhpParser\Node;

use function str_replace;

final class MutateObservableReturnDocBlock
{
    public static function mutate(Node\Stmt\ClassMethod $method): void
    {
        $docblock = $method->getDocComment();
        if (! ($docblock instanceof Doc)) {
            return;
        }

        $method->setDocComment(
            new Doc(
                str_replace(['@return ObservableInterface<', '@return Observable<'], ['@return array<', '@return array<'], $docblock->getText()),
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
