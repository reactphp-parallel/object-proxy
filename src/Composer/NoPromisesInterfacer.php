<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer;

use PhpParser\Node;
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
use stdClass;

use function array_key_exists;
use function array_unshift;
use function class_exists;
use function count;
use function implode;
use function interface_exists;
use function is_array;
use function property_exists;

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
    private Node\Stmt\Use_ $uses;
    /** @var array<string, Node\Stmt\UseUse> */
    private array $useAliases = [];

    /**
     * @param Node\Stmt[] $stmts
     */
    public function __construct(array $stmts)
    {
        $this->uses  = new Node\Stmt\Use_([
            CreateUseUse::create(stdClass::class, 'FakeClassNameButInternallSoYouCanIgnoreThis'),
        ]);
        $this->stmts = $this->iterateStmtsUntilNamespace($stmts);
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
            if (! ($param->type instanceof Node\Name) || array_key_exists((string) $param->type, $this->uses->uses)) {
                continue;
            }

            $namespacedClassType = self::NAMESPACE_GLUE . $this->namespace . self::NAMESPACE_GLUE . (string) $param->type;
            if (array_key_exists((string) $param->type, $this->useAliases)) {
                continue;
            }

            $this->uses->uses[$namespacedClassType] = CreateUseUse::create($namespacedClassType, null);
        }

        if (HasPromiseReturnType::has($method) || HasPromiseDocBlockReturnType::has($method)) {
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
        } elseif (HasObservableReturnType::has($method) || HasObservableDocBlockReturnType::has($method)) {
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
}
