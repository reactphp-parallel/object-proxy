<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer;

use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;

use function array_key_exists;
use function count;
use function current;
use function implode;
use function is_array;
use function property_exists;
use function substr;

final class NoPromisesInterfacer
{
    public const GENERATED_NAMESPACE = [
        'ReactParallel',
        'ObjectProxy',
        'Generated',
        'Interfaces',
    ];
    private const NAMESPACE_GLUE     = '\\';
    /** @var array<Node\Stmt> */
    private array $stmts;
    private string $namespace     = '';
    private string $className     = '';
    private string $interfaceName = '';
    /** @var array<string, string> */
    private array $uses = [];

    /**
     * @param Node\Stmt[] $stmts
     */
    public function __construct(array $stmts)
    {
        $this->stmts = $this->iterateStmts($stmts);
    }

    /**
     * @return array<Node\Stmt>
     */
    public function stmts(): array
    {
        return $this->stmts;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function className(): string
    {
        return $this->className;
    }

    public function interfaceName(): string
    {
        return $this->interfaceName;
    }

    /**
     * @param array<Node\Stmt> $stmts
     *
     * @return array<Node\Stmt>
     */
    private function iterateStmts(array $stmts): array
    {
        $nodes = [];
        foreach ($stmts as $index => $node) {
            $nodes[$index] = $this->inspectNode($node);
        }

        return $nodes;
    }

    private function inspectNode(Node\Stmt $node): Node\Stmt
    {
        if ($node instanceof Node\Stmt\Interface_) {
            $this->className     = $this->namespace . self::NAMESPACE_GLUE . $node->name;
            $this->interfaceName = $this->namespace . self::NAMESPACE_GLUE . $node->name;
        }

        if ($node instanceof Node\Stmt\Namespace_) {
            $node = $this->replaceNamespace($node);
        }

        if ($node instanceof Node\Stmt\Use_) {
            $this->gatherUses($node);
        }

        /**
         * @psalm-suppress RedundantConditiondkp
         * @psalm-suppress UndefinedPropertyFetch
         */
        if (property_exists($node, 'stmts') && is_array($node->stmts)) {
            /** @psalm-suppress UndefinedPropertyAssignment */
            $node->stmts = $this->iterateStmts($node->stmts);
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            return $this->replacePromiseReturnType($node);
        }

        return $node;
    }

    private function replacePromiseReturnType(Node\Stmt\ClassMethod $method): Node\Stmt\ClassMethod
    {
        if ((string) $method->name === '__destruct') {
            return $method;
        }

        foreach ($method->getParams() as $param) {
            if (! ($param->type instanceof Node\Name) || array_key_exists((string) $param->type, $this->uses)) {
                continue;
            }

            $namespacedClassType               = self::NAMESPACE_GLUE . $this->namespace . self::NAMESPACE_GLUE . (string) $param->type;
            $param->type                       = new Node\Name($namespacedClassType);
            $this->uses[(string) $param->type] = $namespacedClassType;
        }

        /**
         * @todo Use FQCN PromiseInterface::class
         * @phpstan-ignore-next-line
         */
        if ((string) $method->getReturnType() === 'PromiseInterface' || ($this->parseReturnTypeFromDocBlock($method) !== null && substr((string) $this->parseReturnTypeFromDocBlock($method)->type, 0, 16) === 'PromiseInterface')) {
            $method->returnType = $this->extractReturnType($method);
        }

        return $method;
    }

    private function replaceNamespace(Node\Stmt\Namespace_ $namespace): Node\Stmt\Namespace_
    {
        if ($this->namespace === '') {
            /**
             * @psalm-suppress PossiblyNullArgument
             * @psalm-suppress PossiblyNullPropertyFetch
             * @phpstan-ignore-next-line
             */
            $this->namespace = implode(self::NAMESPACE_GLUE, $namespace->name->parts);
        }

        $parts = self::GENERATED_NAMESPACE;
        /**
         * @phpstan-ignore-next-line
         */
        foreach ($namespace->name->parts as $part) {
            $parts[] = $part;
        }

        /**
         * @psalm-suppress PossiblyNullPropertyAssignment
         * @phpstan-ignore-next-line
         */
        $namespace->name->parts = $parts;
        $namespace->stmts       = $this->iterateStmts($namespace->stmts);

        return $namespace;
    }

    private function gatherUses(Node\Stmt\Use_ $use): void
    {
        foreach ($use->uses as $singleUse) {
            /** @phpstan-ignore-next-line  */
            $this->uses[$singleUse->alias ?? $singleUse->name->parts[count($singleUse->name->parts) - 1]] = $singleUse->name->toString();
        }
    }

    /** @phpstan-ignore-next-line  */
    private function extractReturnType(Node\Stmt\ClassMethod $method): ?Node\Identifier
    {
        $type = $this->parseReturnTypeFromDocBlock($method);

        if ($type === null) {
            return null;
        }

        $genericType = $type->type;
        if (! property_exists($genericType, 'genericTypes')) {
            return null;
        }

        $type = (string) current($genericType->genericTypes);

        if ($type === 'mixed') {
            return null;
        }

        return new Node\Identifier($type);
    }

    /** @phpstan-ignore-next-line  */
    private function parseReturnTypeFromDocBlock(Node\Stmt\ClassMethod $method): ?ReturnTagValueNode
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
