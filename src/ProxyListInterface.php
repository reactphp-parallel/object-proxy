<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

interface ProxyListInterface
{
    public function has(string $interface): bool;

    /**
     * @return array<string, string>
     */
    public function get(string $interface): array;

    /**
     * @return iterable<string>
     */
    public function interfaces(): iterable;

    /**
     * @return array<string, string>
     */
    public function noPromiseKnownInterfaces(): array;
}
