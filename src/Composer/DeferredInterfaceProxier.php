<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer;

use Doctrine\Common\Annotations\AnnotationReader;
use PhpParser\Comment;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use ReactParallel\ObjectProxy\AbstractGeneratedDeferredProxy;
use ReactParallel\ObjectProxy\Attribute\Defer;
use ReflectionMethod;
use Traversable;

use function array_key_exists;
use function count;
use function current;
use function implode;
use function is_array;
use function iterator_to_array;
use function property_exists;
use function strpos;
use function substr;

use const WyriHaximus\Constants\Boolean\FALSE_;

final class DeferredInterfaceProxier
{
    public const GENERATED_NAMESPACE = [
        'ReactParallel',
        'ObjectProxy',
        'Generated',
        'DeferredProxies',
    ];
    private const NAMESPACE_GLUE     = '\\';
    /** @var array<Node\Stmt> */
    private array $stmts;
    private string $namespace     = '';
    private string $className     = '';
    private string $class         = '';
    private string $interfaceName = '';
    /** @var array<string, string> */
    private array $uses = [];
    private bool $noPromises;

    /**
     * @param Node\Stmt[] $stmts
     */
    public function __construct(array $stmts, bool $noPromises)
    {
        $this->noPromises = $noPromises;
        $this->stmts      = $this->iterateStmts($stmts);
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
            $this->class         = (string) $node->name;
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

        if ($node instanceof Node\Stmt\Interface_) {
            return $this->transformInterfaceIntoClass($node);
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            return $this->populateMethod($node);
        }

        return $node;
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

    private function transformInterfaceIntoClass(Node\Stmt\Interface_ $interface): Node\Stmt\Class_
    {
        $stmts = $this->iterateStmts($interface->stmts);

        return new Node\Stmt\Class_(
            $this->class,
            [
                'extends' => new Node\Name(self::NAMESPACE_GLUE . AbstractGeneratedDeferredProxy::class),
                'flags' => Node\Stmt\Class_::MODIFIER_FINAL,
                'implements' => [
                    new Node\Name(($this->noPromises ? self::NAMESPACE_GLUE . implode(self::NAMESPACE_GLUE, NoPromisesInterfacer::GENERATED_NAMESPACE) : '') . self::NAMESPACE_GLUE . $this->interfaceName),
                ],
                'stmts' => $stmts,
            ],
        );
    }

    private function populateMethod(Node\Stmt\ClassMethod $method): Node\Stmt\ClassMethod
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

        $methodBody = new Node\Expr\MethodCall(
            new Node\Expr\Variable('this'),
            $this->isMethodVoid($method) ? ($this->isDeferrable($method) ? 'deferNotifyMainThread' : 'proxyNotifyMainThread') : ($this->isDeferrable($method) ? 'createDeferredProxy' : 'proxyCallToMainThread'),
            iterator_to_array($this->methodCallArguments($method))
        );

        $method->stmts = [
            $this->wrapMethodBody($method, $methodBody),
        ];

        /**
         * @todo Use FQCN PromiseInterface::class
         * @phpstan-ignore-next-line
         */
        if ($this->noPromises && ((string) $method->getReturnType() === 'PromiseInterface' || ($this->parseReturnTypeFromDocBlock($method) !== null && substr((string) $this->parseReturnTypeFromDocBlock($method)->type, 0, 16) === 'PromiseInterface'))) {
            $method->returnType = $this->extractReturnType($method);
        }
        else if (in_array((string) $method->getReturnType(), ['ObservableInterface', 'Observable']) || ($this->parseReturnTypeFromDocBlock($method) !== null && substr((string) $this->parseReturnTypeFromDocBlock($method)->type, 0, 10) === 'Observable')) {
            $method->returnType = new Node\Identifier('array');
        }

        return $method;
    }

    /**
     * @return Traversable<Node\Arg>
     */
    private function methodCallArguments(Node\Stmt\ClassMethod $method): iterable
    {
        yield new Node\Arg(
            new Node\Expr\ConstFetch(
                new Node\Name('__FUNCTION__'),
            ),
        );

        yield new Node\Arg(
            new Node\Expr\FuncCall(
                new Node\Name('\func_get_args'),
            ),
        );

        if (! $this->isDeferrable($method)) {
            return;
        }

        if (! ($method->getReturnType() instanceof Node\Name)) {
            return;
        }

        /** @psalm-suppress PossiblyInvalidArgument */
        yield new Node\Arg(
            new Node\Expr\ClassConstFetch($method->getReturnType(), 'class'),
        );
    }

    private function wrapMethodBody(Node\Stmt\ClassMethod $method, Node\Expr\MethodCall $methodBody): Node\Stmt
    {
        if ($this->isMethodVoid($method)) {
            return new Node\Stmt\Expression($methodBody);
        }

        /**
         * @todo Use FQCN PromiseInterface::class
         * @phpstan-ignore-next-line
         */
        if ((string) $method->getReturnType() === 'PromiseInterface') {
            $methodBody =  new Node\Expr\MethodCall(
                new Node\Expr\Variable('this'),
                'detectResolvedOrRejectedPromise',
                [new Node\Arg($methodBody)]
            );
        }

        return new Node\Stmt\Return_($methodBody);
    }

    private function isMethodVoid(Node\Stmt\ClassMethod $method): bool
    {
        /**
         * @psalm-suppress PossiblyInvalidCast
         * @phpstan-ignore-next-line
         */
        return (string) $method->getReturnType() === 'void' ? true : $this->isMethodVoidFromDocBlock($method);
    }

    private function isMethodVoidFromDocBlock(Node\Stmt\ClassMethod $method): bool
    {
        /**
         * @psalm-suppress PossiblyNullReference
         */
        return $method->getDocComment() instanceof Comment && strpos($method->getDocComment()->getText(), '@return void') !== FALSE_;
    }

    private function isDeferrable(Node\Stmt\ClassMethod $method): bool
    {
        if (! ($method->getDocComment() instanceof Comment)) {
            return false;
        }

        return (new AnnotationReader())->getMethodAnnotation(new ReflectionMethod($this->interfaceName . '::' . $method->name), Defer::class) instanceof Defer;
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

        if (current($genericType->genericTypes) instanceof GenericTypeNode) {
            $type = (string) current($genericType->genericTypes)->type;
        }

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
