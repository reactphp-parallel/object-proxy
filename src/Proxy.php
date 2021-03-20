<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use parallel\Channel;
use parallel\Channel\Error\Closed;
use React\EventLoop\StreamSelectLoop;
use ReactParallel\EventLoop\EventLoopBridge;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Configuration\Metrics;
use ReactParallel\ObjectProxy\Proxy\Handler;
use ReactParallel\ObjectProxy\Proxy\Instance;
use ReactParallel\ObjectProxy\Proxy\Registry;
use ReactParallel\Streams\Factory as StreamsFactory;
use Throwable;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Registry as MetricsRegistry;
use WyriHaximus\Metrics\Registry\Counters;

use function serialize;
use function unserialize;

use const WyriHaximus\Constants\Boolean\FALSE_;
use const WyriHaximus\Constants\Boolean\TRUE_;

final class Proxy implements EventEmitterInterface
{
    use EventEmitterTrait;

    private const HASNT_PROXYABLE_INTERFACE = false;

    private Factory $factory;
    private ProxyListInterface $proxyList;
    private Channel $in;
    private Registry $registry;
    private ?Counters $counterCreate = null;

    /** @var array<Channel> */
    private array $destruct = [];

    private bool $closed = FALSE_;

    public function __construct(Configuration $configuration)
    {
        $this->factory   = $configuration->factory();
        $this->proxyList = $configuration->proxyList();
        $this->in        = new Channel(Channel::Infinite);
        $this->registry  = new Registry($this->proxyList, $this->in);
        (
            new Handler($this->proxyList, $this->in, $this->factory->loop(), $this->factory->streams(), $this->registry, $configuration->metrics())
        )->on('error', function (Throwable $throwable): void {
            $this->emit('error', [$throwable]);
        });
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

        return $this->proxyList->has($interface);
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
        $in               = new Channel(Channel::Infinite);
        $destruct         = new Channel(Channel::Infinite);
        $this->destruct[] = $destruct;
        $instance         = $this->registry->thread($object, $interface, $in);

        /**
         * @psalm-suppress ArgumentTypeCoercion
         * @phpstan-ignore-next-line
         */
        $metrics = $object instanceof MetricsRegistry ? null : ($this->registry->hasByInterface(MetricsRegistry::class) ? Metrics::create($this->registry->getByInterface(MetricsRegistry::class)->create()) : null);

        $this->factory->call(
            /** @phpstan-ignore-next-line */
            static function (string $object, string $interface, string $hash, ProxyListInterface $proxyList, Channel $in, Channel $destruct, ?Metrics $metrics = null): void {
                $object        = unserialize($object);
                $instance      = new Instance($proxyList, $object, $interface, TRUE_, $in, $hash);
                $eventLoop     = new StreamSelectLoop();
                $streamFactory = new StreamsFactory(new EventLoopBridge($eventLoop));
                $registry      = new Registry($proxyList, $in, $instance);
                new Handler($proxyList, $in, $eventLoop, $streamFactory, $registry, $object instanceof MetricsRegistry ? Metrics::create($object) : $metrics);

                $stop = static function () use ($eventLoop): void {
                    $eventLoop->stop();
                };
                $streamFactory->channel($destruct)->subscribe($stop, $stop, $stop);

                $eventLoop->run();
            },
            [
                serialize($object),
                $interface,
                $instance->hash(),
                $this->proxyList,
                $in,
                $destruct,
                $metrics,
            ]
        );

        if ($this->counterCreate instanceof Counters) {
            $this->counterCreate->counter(new Label('class', $instance->class()), new Label('interface', $interface))->incr();
        }

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
            try {
                $destruct->send('bye');
                $destruct->close();
            } catch (Closed $closed) {
                // @ignoreException
            }
        }

        try {
            $this->in->close();
        } catch (Closed $closed) {
            // @ignoreException
        }
    }

    public function __destruct()
    {
        if ($this->closed === TRUE_) {
            return;
        }

        $this->close();
    }
}
