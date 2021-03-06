<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Generated;

use ReactParallel\ObjectProxy\ProxyList\Proxy;
use ReactParallel\ObjectProxy\ProxyListInterface;

final class ProxyList implements ProxyListInterface
{
    /** @var array<class-string, class-string> */
    private const KNOWN_INTERFACE = ['%s'];

    /** @var array<class-string, class-string> */
    private const NO_PROMISE_KNOWN_INTERFACE_MAP = ['%s'];

    public function has(string $interface): bool
    {
        return array_key_exists($interface, self::KNOWN_INTERFACE);
    }

    public function get(string $interface): Proxy
    {
        return new Proxy(self::KNOWN_INTERFACE[$interface]['direct'], self::KNOWN_INTERFACE[$interface]['deferred']);
    }

    /**
     * @return iterable<string>
     */
    public function interfaces(): iterable {
        yield from array_keys(self::KNOWN_INTERFACE);
    }

    /**
     * @return array<string, string>
     */
    public function noPromiseKnownInterfaces(): array {
        return self::NO_PROMISE_KNOWN_INTERFACE_MAP;
    }
}
