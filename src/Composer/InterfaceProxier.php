<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use ReactParallel\ObjectProxy\AbstractGeneratedProxy;

use function implode;
use function is_array;
use function property_exists;
use function str_replace;

final class InterfaceProxier
{
    public const GENERATED_NAMESPACE = [
        'ReactParallel',
        'ObjectProxy',
        'Generated',
    ];
    /** @var array<Node\Stmt> */
    private array $stmts;
    private string $namespace     = '';
    private string $className     = '';
    private string $interfaceName = '';

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
        if ($node instanceof Node\Stmt\Namespace_) {
            $node = $this->replaceNamespace($node);
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
        $this->namespace = implode('\\', $namespace->name->parts);
        /**
         * @psalm-suppress PossiblyNullPropertyAssignment
         * @phpstan-ignore-next-line
         */
        $namespace->name->parts = self::GENERATED_NAMESPACE;
        $namespace->stmts       = $this->iterateStmts($namespace->stmts);

        return $namespace;
    }

    private function transformInterfaceIntoClass(Node\Stmt\Interface_ $interface): Node\Stmt\Class_
    {
        $this->className     = str_replace('\\', '__', $this->namespace) . '_' . $interface->name . 'Proxy';
        $this->interfaceName = $this->namespace . '\\' . $interface->name;

        return new Node\Stmt\Class_(
            $this->className,
            [
                'extends' => new Node\Name('\\' . AbstractGeneratedProxy::class),
                'flags' => Node\Stmt\Class_::MODIFIER_FINAL,
                'implements' => [
                    new Node\Name('\\' . $this->interfaceName),
                ],
                'stmts' => $this->iterateStmts($interface->stmts),
            ],
        );
    }

    private function populateMethod(Node\Stmt\ClassMethod $method): Node\Stmt\ClassMethod
    {
        $method->stmts = [
            new Node\Stmt\Return_(
                new Node\Expr\MethodCall(
                    new Node\Expr\Variable('this'),
                    'proxyCallToMainThread',
                    [
                        new Node\Arg(
                            new Node\Expr\ConstFetch(
                                new Node\Name('__FUNCTION__'),
                            ),
                        ),
                        new Node\Arg(
                            new Node\Expr\FuncCall(
                                new Node\Name('\func_get_args'),
                            ),
                        ),
                    ]
                )
            ),
        ];
        $method->setDocComment(new Doc(''));

        return $method;
    }
}
