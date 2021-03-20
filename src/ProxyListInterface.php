<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use ReactParallel\ObjectProxy\ProxyList\Proxy;

interface ProxyListInterface
{
    public function has(string $interface): bool;

    public function get(string $interface): Proxy;

    /**
     * @return iterable<string>
     */
    public function interfaces(): iterable;

    /**
     * @return array<string, string>
     */
    public function noPromiseKnownInterfaces(): array;
}
