<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use React\EventLoop\StreamSelectLoop;
use ReactParallel\EventLoop\EventLoopBridge;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Configuration\Metrics;
use ReactParallel\ObjectProxy\Generated\ProxyList;
use ReactParallel\ObjectProxy\Proxy\Handler;
use ReactParallel\ObjectProxy\Proxy\Instance;
use ReactParallel\ObjectProxy\Proxy\Registry;
use ReactParallel\Streams\Factory as StreamsFactory;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Registry\Counters;

use function array_key_exists;
use function bin2hex;
use function random_bytes;
use function serialize;
use function unserialize;

use const WyriHaximus\Constants\Boolean\FALSE_;
use const WyriHaximus\Constants\Boolean\TRUE_;

final class Proxy extends ProxyList
{
    private const RANDOM_BYTES_LENGTH       = 13;
    private const HASNT_PROXYABLE_INTERFACE = false;

    private Factory $factory;
    private Channel $in;
    private Registry $registry;
    private ?Counters $counterCreate = null;

    /** @var array<Channel> */
    private array $destruct = [];

    private bool $closed = FALSE_;

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
        if ($this->closed === TRUE_) {
            throw ClosedException::create();
        }

        return array_key_exists($interface, self::KNOWN_INTERFACE);
    }

    public function share(object $object, string $interface): object
    {
        if ($this->closed === TRUE_) {
            throw ClosedException::create();
        }

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
        if ($this->closed === TRUE_) {
            throw ClosedException::create();
        }

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

    public function thread(object $object, string $interface): object
    {
        if ($this->closed === TRUE_) {
            throw ClosedException::create();
        }

        if ($this->has($interface) === self::HASNT_PROXYABLE_INTERFACE) {
            throw NonExistentInterface::create($interface);
        }

        /**
         * @psalm-suppress EmptyArrayAccess
         */
        $in       = new Channel(Channel::Infinite);
        $destruct = new Channel(Channel::Infinite);
        $instance = new Instance($object, $interface, TRUE_, $in, bin2hex(random_bytes(self::RANDOM_BYTES_LENGTH)));

        $this->factory->call(
            static function (string $object, string $interface, string $hash, Channel $in, Channel $destruct): void {
                $object        = unserialize($object);
                $instance      = new Instance($object, $interface, TRUE_, $in, $hash);
                $eventLoop     = new StreamSelectLoop();
                $streamFactory = new StreamsFactory(new EventLoopBridge($eventLoop));
                $registry      = new Registry($in, $instance);
                new Handler($in, $eventLoop, $streamFactory, $registry);

                $stop = static function () use ($eventLoop): void {
                    $eventLoop->stop();
                };
                $streamFactory->channel($destruct)->subscribe($stop, $stop, $stop);

                $eventLoop->run();
            },
            [serialize($object), $interface, $instance->hash(), $in, $destruct]
        );

        /** @psalm-suppress InvalidStringClass */
        return $instance->create();
    }

    public function close(): void
    {
        if ($this->closed === TRUE_) {
            throw ClosedException::create();
        }

        $this->closed = TRUE_;

        foreach ($this->destruct as $destruct) {
            $destruct->send('bye');
            $destruct->close();
        }

        $this->in->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
