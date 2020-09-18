<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use ReactParallel\ObjectProxy\AbstractGeneratedProxy;

use function array_key_exists;
use function count;
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
        $this->className     = str_replace(self::NAMESPACE_GLUE, '__', $this->namespace) . '_' . $interface->name . 'Proxy';
        $this->interfaceName = $this->namespace . self::NAMESPACE_GLUE . $interface->name;

        return new Node\Stmt\Class_(
            $this->className,
            [
                'extends' => new Node\Name(self::NAMESPACE_GLUE . AbstractGeneratedProxy::class),
                'flags' => Node\Stmt\Class_::MODIFIER_FINAL,
                'implements' => [
                    new Node\Name(self::NAMESPACE_GLUE . $this->interfaceName),
                ],
                'stmts' => $this->iterateStmts($interface->stmts),
            ],
        );
    }

    private function populateMethod(Node\Stmt\ClassMethod $method): Node\Stmt\ClassMethod
    {
        foreach ($method->getParams() as $param) {
            if (! ($param->type instanceof Node\Name) || array_key_exists((string) $param->type, $this->uses)) {
                continue;
            }

            $namespacedClassType               = self::NAMESPACE_GLUE . $this->namespace . self::NAMESPACE_GLUE . (string) $param->type;
            $param->type                       = new Node\Name($namespacedClassType);
            $this->uses[(string) $param->type] = $namespacedClassType;
        }

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
