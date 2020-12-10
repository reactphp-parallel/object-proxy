<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Configuration\Metrics;
use ReactParallel\ObjectProxy\Generated\ProxyList;
use ReactParallel\ObjectProxy\Proxy\Handler;
use ReactParallel\ObjectProxy\Proxy\Registry;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Registry\Counters;

use function array_key_exists;

final class Proxy extends ProxyList
{
    private const HASNT_PROXYABLE_INTERFACE = false;

    private Factory $factory;
    private Channel $in;
    private Registry $registry;
    private ?Counters $counterCreate = null;

    /** @var array<Channel> */
    private array $destruct = [];

    public function __construct(Configuration $configuration)
    {
        $this->factory  = $configuration->factory();
        $this->in       = new Channel(Channel::Infinite);
        $this->registry = new Registry($this->in);
        new Handler($this->in, $this->factory->loop(), $this->factory->streams(), $this->registry, $configuration->metrics());
        $metrics = $configuration->metrics();
        if (! ($metrics instanceof Metrics)) {
            return;
        }

        $this->counterCreate = $metrics->createCounter();
    }

    public function has(string $interface): bool
    {
        return array_key_exists($interface, self::KNOWN_INTERFACE);
    }

    public function share(object $object, string $interface): object
    {
        if ($this->has($interface) === self::HASNT_PROXYABLE_INTERFACE) {
            throw NonExistentInterface::create($interface);
        }

        $instance = $this->registry->share($object, $interface);

        if ($this->counterCreate instanceof Counters) {
            $this->counterCreate->counter(new Label('class', $instance->class()), new Label('interface', $interface))->incr();
        }

        return $this->create($object, $interface);
    }

    public function create(object $object, string $interface): object
    {
        if ($this->has($interface) === self::HASNT_PROXYABLE_INTERFACE) {
            throw NonExistentInterface::create($interface);
        }

        if ($this->registry->hasByInterface($interface)) {
            return $this->registry->getByInterface($interface)->create();
        }

        $instance = $this->registry->create($object, $interface);

        if ($this->counterCreate instanceof Counters) {
            $this->counterCreate->counter(new Label('class', $instance->class()), new Label('interface', $interface))->incr();
        }

        /** @psalm-suppress InvalidStringClass */
        return $instance->create();
    }

    public function __destruct()
    {
        foreach ($this->destruct as $destruct) {
            $destruct->send('bye');
            $destruct->close();
        }

        $this->in->close();
    }
}
