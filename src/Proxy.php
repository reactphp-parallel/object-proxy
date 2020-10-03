<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Generated\ProxyList;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Destruct;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Registry;
use WyriHaximus\Metrics\Registry\Counters;

use function array_key_exists;
use function bin2hex;
use function get_class;
use function is_object;
use function random_bytes;

final class Proxy extends ProxyList
{
    private const HASNT_PROXYABLE_INTERFACE = false;

    private Factory $factory;
    private ?Counters $counterCreate   = null;
    private ?Counters $counterCall     = null;
    private ?Counters $counterDestruct = null;

    private Channel $in;

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, string|false> */
    private array $detectedClasses = [];

    public function __construct(Factory $factory)
    {
        $this->factory = $factory;
        $this->in      = new Channel(Channel::Infinite);
        $this->setUpHandlers();
    }

    public function withMetrics(Registry $registry): self
    {
        $self                  = clone $this;
        $self->counterCreate   = $registry->counter(
            'react_parallel_object_proxy_create',
            'Number of create proxies by the object proxy',
            new Label\Name('class'),
            new Label\Name('interface'),
        );
        $self->counterCall     = $registry->counter(
            'react_parallel_object_proxy_call',
            'The number of calls from worker threads through proxies to the main thread',
            new Label\Name('class'),
            new Label\Name('interface'),
        );
        $self->counterDestruct = $registry->counter(
            'react_parallel_object_proxy_destruct',
            'Number of destroyed proxies by the garbage collector',
            new Label\Name('interface'),
        );
        $self->in              = new Channel(Channel::Infinite);
        $self->setUpHandlers();

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

        if ($this->counterCreate instanceof Counters) {
            $this->counterCreate->counter(new Label('class', get_class($object)), new Label('interface', $interface))->incr();
        }

        $class = self::KNOWN_INTERFACE[$interface];
        $hash  = bin2hex(random_bytes(13));

        $this->instances[$hash] = $object;

        /** @psalm-suppress InvalidStringClass */
        return new $class($this->in, $hash);
    }

    public function __destruct()
    {
        $this->in->close();
    }

    private function setUpHandlers(): void
    {
        $this->factory->streams()->channel($this->in)->subscribe(function (object $message): void {
            if ($message instanceof Call) {
                $this->handleCall($message);
            }

            if (! ($message instanceof Destruct)) {
                return;
            }

            $this->factory->loop()->addTimer(10, function () use ($message): void {
                $this->handleDestruct($message);
            });
        });
    }

    private function handleCall(Call $call): void
    {
        $object = $this->instances[$call->hash()];
        if ($this->counterCall instanceof Counters) {
            $this->counterCall->counter(new Label('class', get_class($object)), new Label('interface', $call->interface()))->incr();
        }

        /** @phpstan-ignore-next-line */
        $outcome = $object->{$call->method()}(...$call->args());

        if (is_object($outcome)) {
            $outcomeClass = get_class($outcome);
            if (array_key_exists($outcomeClass, $this->detectedClasses) === self::HASNT_PROXYABLE_INTERFACE) {
                $this->detectedClasses[$outcomeClass] = $this->getInterfaceForOutcome($outcome) ?? self::HASNT_PROXYABLE_INTERFACE;
            }

            if ($this->detectedClasses[$outcomeClass] !== self::HASNT_PROXYABLE_INTERFACE) {
                /** @psalm-suppress PossiblyFalseArgument */
                $outcome = $this->create($outcome, $this->detectedClasses[$outcomeClass]);
            }
        }

        $call->channel()->send($outcome);
    }

    private function handleDestruct(Destruct $destruct): void
    {
        unset($this->instances[$destruct->hash()]);

        if (! ($this->counterDestruct instanceof Counters)) {
            return;
        }

        $this->counterDestruct->counter(new Label('interface', $destruct->interface()));
    }

    /** @phpstan-ignore-next-line */
    private function getInterfaceForOutcome(object $outcome): ?string
    {
        foreach (self::KNOWN_INTERFACE as $interface => $proxy) {
            if ($outcome instanceof $interface) {
                return $interface;
            }
        }

        return null;
    }
}
