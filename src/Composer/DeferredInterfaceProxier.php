<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer;

use Doctrine\Common\Annotations\AnnotationReader;
use PhpParser\Comment;
use PhpParser\Node;
use ReactParallel\ObjectProxy\AbstractGeneratedDeferredProxy;
use ReactParallel\ObjectProxy\Attribute\Defer;
use ReactParallel\ObjectProxy\Composer\Mutators\CreateUseUse;
use ReactParallel\ObjectProxy\Composer\Mutators\ExtractImportsFromMethodDocBlock;
use ReactParallel\ObjectProxy\Composer\Mutators\ExtractReturnType;
use ReactParallel\ObjectProxy\Composer\Mutators\HasObservableDocBlockReturnType;
use ReactParallel\ObjectProxy\Composer\Mutators\HasObservableReturnType;
use ReactParallel\ObjectProxy\Composer\Mutators\HasPromiseDocBlockReturnType;
use ReactParallel\ObjectProxy\Composer\Mutators\HasPromiseReturnType;
use ReactParallel\ObjectProxy\Composer\Mutators\MutateObservableReturnDocBlock;
use ReactParallel\ObjectProxy\Composer\Mutators\MutatePromiseReturnDocBlock;
use ReactParallel\ObjectProxy\Composer\Mutators\ParseReturnTypeFromDocBlock;
use ReflectionMethod;
use stdClass;
use Traversable;

use function array_key_exists;
use function array_unshift;
use function class_exists;
use function count;
use function implode;
use function interface_exists;
use function is_array;
use function iterator_to_array;
use function property_exists;
use function strpos;

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
    private Node\Stmt\Use_ $uses;
    /** @var array<string, Node\Stmt\UseUse> */
    private array $useAliases = [];
    private bool $noPromises;

    /**
     * @param Node\Stmt[] $stmts
     */
    public function __construct(array $stmts, bool $noPromises)
    {
        $this->uses       = new Node\Stmt\Use_([
            CreateUseUse::create(stdClass::class, 'FakeClassNameButInternallSoYouCanIgnoreThis'),
        ]);
        $this->noPromises = $noPromises;
        $this->stmts      = $this->iterateStmtsUntilNamespace($stmts);
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
            $updatedNode = $this->inspectNode($node);
            if ($updatedNode === null) {
                continue;
            }

            $nodes[$index] = $updatedNode;
        }

        return $nodes;
    }

    /**
     * @param array<Node\Stmt> $stmts
     *
     * @return array<Node\Stmt>
     */
    private function iterateStmtsUntilNamespace(array $stmts): array
    {
        foreach ($stmts as $index => $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $node        = $this->replaceNamespace($node);
                $node->stmts = [$this->uses, ...$node->stmts];
                continue;
            }

            /**
             * @psalm-suppress UndefinedPropertyFetch
             */
            if (! property_exists($node, 'stmts') || ! is_array($node->stmts)) {
                continue;
            }

            /**
             * @psalm-suppress UndefinedPropertyAssignment
             */
            $node->stmts = $this->iterateStmtsUntilNamespace($node->stmts);
        }

        return $stmts;
    }

    /**
     * @phpstan-ignore-next-line
     */
    private function inspectNode(Node\Stmt $node): ?Node\Stmt
    {
        if ($node instanceof Node\Stmt\Interface_) {
            $this->class         = (string) $node->name;
            $this->className     = $this->namespace . self::NAMESPACE_GLUE . $node->name;
            $this->interfaceName = $this->namespace . self::NAMESPACE_GLUE . $node->name;
        }

        if ($node instanceof Node\Stmt\Namespace_) {
            $node = $this->replaceNamespace($node);
            array_unshift($node->stmts, $this->uses);
        }

        if ($node instanceof Node\Stmt\Use_) {
            $this->gatherUses($node);

            return null;
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
        foreach ($namespace->name->parts ?? [] as $part) {
            $parts[] = $part;
        }

        /**
         * @psalm-suppress PossiblyNullPropertyAssignment
         * @psalm-suppress PropertyTypeCoercion
         * @phpstan-ignore-next-line
         */
        $namespace->name->parts = $parts;
        $namespace->stmts       = $this->iterateStmts($namespace->stmts);

        return $namespace;
    }

    private function gatherUses(Node\Stmt\Use_ $use): void
    {
        foreach ($use->uses as $singleUse) {
            $alias                                       = (string) ($singleUse->alias ?? $singleUse->name->parts[count($singleUse->name->parts) - 1]);
            $this->uses->uses[(string) $singleUse->name] = CreateUseUse::create((string) $singleUse->name, $singleUse->alias !== null ? (string) $singleUse->alias : null);
            $this->useAliases[$alias]                    = $this->uses->uses[(string) $singleUse->name];
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
            if (! ($param->type instanceof Node\Name) || array_key_exists((string) $param->type, $this->uses->uses)) {
                continue;
            }

            $namespacedClassType = $this->namespace . self::NAMESPACE_GLUE . (string) $param->type;
            if (array_key_exists((string) $param->type, $this->useAliases)) {
                continue;
            }

            $this->uses->uses[$namespacedClassType] = CreateUseUse::create($namespacedClassType, null);
        }

        $methodBody = new Node\Expr\MethodCall(
            new Node\Expr\Variable('this'),
            $this->isMethodVoid($method) ? ($this->isDeferrable($method) ? 'deferNotifyMainThread' : 'proxyNotifyMainThread') : ($this->isDeferrable($method) ? 'createDeferredProxy' : 'proxyCallToMainThread'),
            iterator_to_array($this->methodCallArguments($method))
        );

        $method->stmts = [
            $this->wrapMethodBody($method, $methodBody),
        ];

        if ($this->noPromises && (HasPromiseReturnType::has($method) || HasPromiseDocBlockReturnType::has($method))) {
            $method->returnType = ExtractReturnType::extract($method);
            if (ParseReturnTypeFromDocBlock::parse($method) !== null) {
                MutatePromiseReturnDocBlock::mutate($method);
            }

            foreach (ExtractImportsFromMethodDocBlock::extract($method, $this->namespace) as $alias => $fqcn) {
                if (array_key_exists($alias, $this->useAliases)) {
                    continue;
                }

                $this->uses->uses[$fqcn] = CreateUseUse::create($fqcn, $alias);
            }
        } elseif ($this->noPromises && (HasObservableReturnType::has($method) || HasObservableDocBlockReturnType::has($method))) {
            $method->returnType = new Node\Identifier('array');
            if (ParseReturnTypeFromDocBlock::parse($method) !== null) {
                MutateObservableReturnDocBlock::mutate($method);
            }

            foreach (ExtractImportsFromMethodDocBlock::extract($method, $this->namespace) as $alias => $fqcn) {
                if (array_key_exists($alias, $this->useAliases)) {
                    continue;
                }

                $this->uses->uses[$fqcn] = CreateUseUse::create($fqcn, $alias);
            }
        }

        $returnType = ExtractReturnType::extract($method);
        if ($returnType !== null) {
            $possibleSameNamespaceClass = $this->namespace . self::NAMESPACE_GLUE . (string) $returnType;
            if ((class_exists($possibleSameNamespaceClass) || interface_exists($possibleSameNamespaceClass)) && ! array_key_exists((string) $returnType, $this->useAliases)) {
                $this->uses->uses[$possibleSameNamespaceClass] = CreateUseUse::create($possibleSameNamespaceClass, null);
            }
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
         * @psalm-suppress PossiblyInvalidCast
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
