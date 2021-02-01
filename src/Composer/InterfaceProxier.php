<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer;

use Doctrine\Common\Annotations\AnnotationReader;
use PhpParser\Builder\Method;
use PhpParser\Comment;
use PhpParser\Node;
use ReactParallel\ObjectProxy\AbstractGeneratedProxy;
use ReactParallel\ObjectProxy\Attribute\Defer;
use ReflectionMethod;
use Traversable;

use function array_key_exists;
use function count;
use function implode;
use function is_array;
use function iterator_to_array;
use function property_exists;
use function str_replace;
use function strpos;

use const WyriHaximus\Constants\Boolean\FALSE_;

final class InterfaceProxier
{
    public const GENERATED_NAMESPACE = [
        'ReactParallel',
        'ObjectProxy',
        'Generated',
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
            $this->className     = str_replace(self::NAMESPACE_GLUE, '__', $this->namespace) . '_' . $node->name . 'Proxy';
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
        /**
         * @psalm-suppress PossiblyNullArgument
         * @psalm-suppress PossiblyNullPropertyFetch
         * @phpstan-ignore-next-line
         */
        $this->namespace = implode(self::NAMESPACE_GLUE, $namespace->name->parts);
        /**
         * @psalm-suppress PossiblyNullPropertyAssignment
         * @phpstan-ignore-next-line
         */
        $namespace->name->parts = self::GENERATED_NAMESPACE;
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
        $stmts   = $this->iterateStmts($interface->stmts);
        $stmts[] = (new Method('__destruct'))->addStmt(
            new Node\Stmt\Expression(
                new Node\Expr\MethodCall(
                    new Node\Expr\Variable('this'),
                    'notifyMainThreadAboutDestruction',
                )
            ),
        )->makePublic()->makeFinal()->getNode();

        return new Node\Stmt\Class_(
            $this->className,
            [
                'extends' => new Node\Name(self::NAMESPACE_GLUE . AbstractGeneratedProxy::class),
                'flags' => Node\Stmt\Class_::MODIFIER_FINAL,
                'implements' => [
                    new Node\Name(self::NAMESPACE_GLUE . $this->interfaceName),
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
}
