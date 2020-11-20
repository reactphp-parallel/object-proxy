<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Configuration\Metrics;
use ReactParallel\ObjectProxy\Generated\ProxyList;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Destruct;
use ReactParallel\ObjectProxy\Message\Existence;
use ReactParallel\ObjectProxy\Message\Link;
use ReactParallel\ObjectProxy\Message\Notify;
use ReactParallel\ObjectProxy\Message\Parcel;
use ReactParallel\ObjectProxy\Proxy\Instance;
use Rx\Observable;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Registry\Counters;

use function ApiClients\Tools\Rx\observableFromArray;
use function array_key_exists;
use function array_reverse;
use function array_shift;
use function assert;
use function get_class;
use function is_object;
use function spl_object_hash;
use function WyriHaximus\iteratorOrArrayToArray;

use const WyriHaximus\Constants\Boolean\FALSE_;
use const WyriHaximus\Constants\Numeric\ZERO;

final class Proxy extends ProxyList
{
    private const DESTRUCT_DEFERENCE_SECONDS = 10;
    private const HASNT_PROXYABLE_INTERFACE  = false;

    private Factory $factory;
    private ?Counters $counterCreate   = null;
    private ?Counters $counterNotify   = null;
    private ?Counters $counterCall     = null;
    private ?Counters $counterDestruct = null;

    private Channel $in;

    /** @var array<string, Instance> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $shared = [];

    /** @var array<string, string|false> */
    private array $detectedClasses = [];

    public function __construct(Configuration $configuration)
    {
        $this->factory = $configuration->factory();
        $metrics       = $configuration->metrics();
        if ($metrics instanceof Metrics) {
            $this->counterCreate   = $metrics->createCounter();
            $this->counterCall     = $metrics->callCounter();
            $this->counterNotify   = $metrics->notifyCounter();
            $this->counterDestruct = $metrics->destructCounter();
        }

        $this->in = new Channel(Channel::Infinite);
        $this->setUpHandlers();
    }

    public function has(string $interface): bool
    {
        return array_key_exists($interface, self::KNOWN_INTERFACE);
    }

    public function create(object $object, string $interface, bool $share = FALSE_): object
    {
        if ($this->has($interface) === self::HASNT_PROXYABLE_INTERFACE) {
            throw NonExistentInterface::create($interface);
        }

        if (array_key_exists($interface, $this->shared)) {
            /**
             * @psalm-suppress EmptyArrayAccess
             */
            $class    = self::KNOWN_INTERFACE[$interface]['direct'];
            $instance = $this->instances[$this->shared[$interface]];
            $hash     = $instance->class() . '___' . spl_object_hash($object);

            /** @psalm-suppress InvalidStringClass */
            return new $class($this->in, $hash);
        }

        /**
         * @psalm-suppress EmptyArrayAccess
         */
        $class    = self::KNOWN_INTERFACE[$interface]['direct'];
        $instance = new Instance($object, $interface, $share);
        $hash     = $instance->class() . '___' . spl_object_hash($object);

        $this->instances[$hash] = $instance;
        if ($share) {
            $this->shared[$interface] = $hash;
        }

        if ($this->counterCreate instanceof Counters) {
            $this->counterCreate->counter(new Label('class', $this->instances[$hash]->class()), new Label('interface', $interface))->incr();
        }

        /** @psalm-suppress InvalidStringClass */
        return new $class($this->in, $hash);
    }

    public function __destruct()
    {
        $this->in->close();
    }

    private function setUpHandlers(): void
    {
        $this->factory->streams()->channel($this->in)->flatMap(static fn (object $message): Observable => observableFromArray($message instanceof Parcel ? iteratorOrArrayToArray($message->messages()) : [$message]))->subscribe(function (object $message): void {
            if ($message instanceof Existence) {
                $this->handleExistence($message);

                return;
            }

            if ($message instanceof Notify) {
                $this->handleNotify($message);

                return;
            }

            if ($message instanceof Call) {
                $this->handleCall($message);

                return;
            }

            if (! ($message instanceof Destruct)) {
                return;
            }

            $this->factory->loop()->addTimer(self::DESTRUCT_DEFERENCE_SECONDS, function () use ($message): void {
                $this->handleDestruct($message);
            });
        });
    }

    private function handleExistence(Existence $existence): void
    {
        if (! array_key_exists($existence->hash(), $this->instances)) {
            return;
        }

        $instance = $this->instances[$existence->hash()];
        $instance->reference($existence->objectHash());
    }

    private function handleNotify(Notify $notify): void
    {
        if (! array_key_exists($notify->hash(), $this->instances) && ($notify->link() === null || ! array_key_exists($notify->link()->rootHash(), $this->instances))) {
            return;
        }

        if ($notify->link() instanceof Link) {
            $instance = $this->instances[$notify->link()->rootHash()];
            $object   = $this->followChain($notify->link());

            if ($object === null) {
                return;
            }

            $instance = new Instance($object, $instance->interface(), false);
        } else {
            $instance = $this->instances[$notify->hash()];
            $instance->reference($notify->objectHash());
        }

        if ($this->counterNotify instanceof Counters) {
            $this->counterNotify->counter(new Label('class', $instance->class()), new Label('interface', $instance->interface()))->incr();
        }

        /** @phpstan-ignore-next-line */
        $instance->object()->{$notify->method()}(...$notify->args());
    }

    private function handleCall(Call $call): void
    {
        if (! array_key_exists($call->hash(), $this->instances) && ($call->link() === null || ! array_key_exists($call->link()->rootHash(), $this->instances))) {
            return;
        }

        if ($call->link() instanceof Link) {
            $instance = $this->instances[$call->link()->rootHash()];
            $object   = $this->followChain($call->link());

            if ($object === null) {
                return;
            }

            $instance = new Instance($object, $instance->interface(), FALSE_);
        } else {
            $instance = $this->instances[$call->hash()];
            $instance->reference($call->objectHash());
        }

        if ($this->counterCall instanceof Counters) {
            $this->counterCall->counter(new Label('class', $instance->class()), new Label('interface', $instance->interface()))->incr();
        }

        /** @phpstan-ignore-next-line */
        $outcome = $instance->object()->{$call->method()}(...$call->args());

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
        if (! array_key_exists($destruct->hash(), $this->instances)) {
            return;
        }

        $instance = $this->instances[$destruct->hash()];
        $count    = $instance->dereference($destruct->objectHash());

        $this->countDestruct($instance);

        if ($instance->isLocked()) {
            return;
        }

        if ($count !== ZERO) {
            return;
        }

        unset($this->instances[$destruct->hash()]);
    }

    private function countDestruct(Instance $instance): void
    {
        if (! ($this->counterDestruct instanceof Counters)) {
            return;
        }

        $this->counterDestruct->counter(new Label('class', $instance->class()), new Label('interface', $instance->interface()))->incr();
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

    /** @phpstan-ignore-next-line */
    private function followChain(Link $link): ?object
    {
        $chain = [];
        do {
            $chain[] = $link;
            $link    = $link->link();
        } while ($link instanceof Link);

        $chain = array_reverse($chain);
        $link  = array_shift($chain);
        /** @psalm-suppress RedundantCondition */
        assert($link instanceof Link);

        if (! array_key_exists($link->hash(), $this->instances)) {
            return null;
        }

        /** @phpstan-ignore-next-line */
        $result = $this->instances[$link->hash()]->object()->{$link->method()}(...$link->args());
        /** @phpstan-ignore-next-line */
        foreach ($chain as $link) {
            /** @phpstan-ignore-next-line */
            $result = $result->{$link->method()}(...$link->args());
        }

        return $result;
    }
}
