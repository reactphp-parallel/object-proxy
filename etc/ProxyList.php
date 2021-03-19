<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Generated;

use ReactParallel\ObjectProxy\ProxyListInterface;

final class ProxyList implements ProxyListInterface
{
    /** @var array<class-string, class-string> */
    private const KNOWN_INTERFACE = ['%s'];

    /** @var array<class-string, class-string> */
    private const NO_PROMISE_KNOWN_INTERFACE_MAP = ['%s'];

    /**
     * @return array<string,
     */
    public function knownInterfaces(): array {
        return self::KNOWN_INTERFACE;
    }

    /**
     * @return array<string, string>
     */
    public function noPromiseKnownInterfaces(): array {
        return self::NO_PROMISE_KNOWN_INTERFACE_MAP;
    }
}
