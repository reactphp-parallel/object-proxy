<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use React\Datagram\Factory as DatagramFactory;
use React\Datagram\Socket;
use React\Promise\PromiseInterface;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Generated\ProxyList;
use ReactParallel\ObjectProxy\Message\Destruct;
use ReactParallel\ObjectProxy\Proxy\CallHandler;
use ReactParallel\ObjectProxy\Proxy\DestructionHandler;
use Rx\Observable\FromEventEmitterObservable;
use Throwable;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Registry;
use WyriHaximus\Metrics\Registry\Counters;

use function array_key_exists;
use function explode;
use function get_class;
use function random_int;
use function React\Promise\resolve;

final class Proxy extends ProxyList
{
    private const HASNT_PROXYABLE_INTERFACE = false;

    private Factory $factory;
    private CallHandler $callHandler;
    private ?Counters $counter = null;

    private ?Socket $destructionChannel  = null;
    private ?int $destructionChannelPort = null;
    /** @var array<string, Channel> */
    private array $channels = [];

    public function __construct(Factory $factory)
    {
        $this->factory     = $factory;
        $this->callHandler = new CallHandler($this);
        $this->setUpSocketServer(new DestructionHandler());
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
        $self->setUpSocketServer((new DestructionHandler())->withMetrics($registry));

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

        $output                           = new Channel(Channel::Infinite);
        $this->channels[(string) $output] = $output;

        $this->factory->streams()->channel($output)->subscribe(
            ($this->callHandler)($object, $interface)
        );

        $class = self::KNOWN_INTERFACE[$interface];

        /** @psalm-suppress InvalidStringClass */
        return new $class((string) $output, $this->destructionChannelPort);
    }

    public function __destruct()
    {
        foreach ($this->channels as $channel) {
            try {
                $channel->close();
            } catch (Throwable $throwable) { // @phpstan-ignore-line
                // @ignoreException
            }
        }

        $this->channels = [];

        if (! ($this->destructionChannel instanceof Socket)) {
            return;
        }

        $this->destructionChannel->close();
    }

    private function setUpSocketServer(DestructionHandler $destructionHandler): void
    {
        $this->startSocketServer(random_int(10_000, 50_000))->then(function (Socket $server) use ($destructionHandler): void {
            $server->on('error', static function ($e) {
                var_export([__FILE__, __LINE__, (string)$e]);
            });
            $this->destructionChannel     = $server;
            $this->destructionChannelPort = (int) explode(':', $server->getLocalAddress())[1];
            (new FromEventEmitterObservable($server, 'message'))->map(static fn (string $message): Destruct => unserialize($message))->subscribe($destructionHandler);
        });
    }

    private function startSocketServer(int $port): PromiseInterface
    {
        return (new DatagramFactory($this->factory->loop()))->createServer('127.0.0.1:' . $port)->then(null, fn (): PromiseInterface => resolve($this->startSocketServer(++$port)));
    }
}
