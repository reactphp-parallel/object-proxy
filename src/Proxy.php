<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Generated\ProxyList;
use ReactParallel\ObjectProxy\Proxy\CallHandler;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Registry;
use WyriHaximus\Metrics\Registry\Counters;

use function array_key_exists;
use function get_class;

final class Proxy extends ProxyList
{
    private const HASNT_PROXYABLE_INTERFACE = false;

    private Factory $factory;
    private CallHandler $callHandler;
    private ?Counters $counter = null;

    public function __construct(Factory $factory)
    {
        $this->factory     = $factory;
        $this->callHandler = new CallHandler($this);
    }

    public function withMetrics(Registry $registry): self
    {
        $self              = clone $this;
        $self->counter     = $registry->counter(
            'react_parallel_object_proxy_create',
            'Number of create proxies by the object proxy',
            new Label\Name('class'),
            new Label\Name('interface'),
        );
        $self->callHandler = (new CallHandler($self))->withMetrics($registry);

        return $self;
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

        if ($this->counter instanceof Counters) {
            $this->counter->counter(new Label('class', get_class($object)), new Label('interface', $interface))->incr();
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
