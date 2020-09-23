<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Generated\ProxyList;
use ReactParallel\ObjectProxy\Proxy\CallHandler;

use function array_key_exists;

final class Proxy extends ProxyList
{
    private const HASNT_PROXYABLE_INTERFACE = false;

    private Factory $factory;
    private CallHandler $callHandler;

    public function __construct(Factory $factory)
    {
        $this->factory     = $factory;
        $this->callHandler = new CallHandler($this);
    }

    public function has(string $interface): bool
    {
        return array_key_exists($interface, self::KNOWN_INTERFACE);
    }

    public function create(object $object, string $interface): object
    {
        if ($this->has($interface) === self::HASNT_PROXYABLE_INTERFACE) {
            throw NonExistentInterface::create($interface);
        }

        $output = new Channel(Channel::Infinite);

        $this->factory->streams()->channel($output)->subscribe(
            ($this->callHandler)($object, $interface)
        );

        $class = self::KNOWN_INTERFACE[$interface];

        /** @psalm-suppress InvalidStringClass */
        return new $class($output);
    }
}
