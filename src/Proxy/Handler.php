<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Proxy;

use parallel\Channel;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use ReactParallel\ObjectProxy\Configuration\Metrics;
use ReactParallel\ObjectProxy\Generated\ProxyList;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Destruct;
use ReactParallel\ObjectProxy\Message\Existence;
use ReactParallel\ObjectProxy\Message\Link;
use ReactParallel\ObjectProxy\Message\Notify;
use ReactParallel\ObjectProxy\Message\Parcel;
use ReactParallel\ObjectProxy\NonExistentInterface;
use ReactParallel\Streams\Factory as StreamsFactory;
use Rx\Observable;
use Rx\ObservableInterface;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Registry\Counters;

use function ApiClients\Tools\Rx\observableFromArray;
use function array_key_exists;
use function array_reverse;
use function array_shift;
use function get_class;
use function is_object;
use function WyriHaximus\iteratorOrArrayToArray;

use const WyriHaximus\Constants\Boolean\FALSE_;
use const WyriHaximus\Constants\Numeric\ZERO;

final class Handler extends ProxyList
{
    private const DESTRUCT_DEFERENCE_SECONDS = 10;
    private const HASNT_PROXYABLE_INTERFACE  = false;

    private Channel $in;
    private LoopInterface $loop;
    private StreamsFactory $factory;
    private Registry $registry;
    private ?Counters $counterCreate   = null;
    private ?Counters $counterNotify   = null;
    private ?Counters $counterCall     = null;
    private ?Counters $counterDestruct = null;
    /** @var array<string, string|false> */
    private array $detectedClasses = [];

    /** @phpstan-ignore-next-line */
    public function __construct(Channel $in, LoopInterface $loop, StreamsFactory $factory, Registry $registry, ?Metrics $metrics = null)
    {
        $this->in       = $in;
        $this->loop     = $loop;
        $this->factory  = $factory;
        $this->registry = $registry;

        if ($metrics instanceof Metrics) {
            $this->counterCreate   = $metrics->createCounter();
            $this->counterCall     = $metrics->callCounter();
            $this->counterNotify   = $metrics->notifyCounter();
            $this->counterDestruct = $metrics->destructCounter();
        }

        $this->setUpHandlers();
    }

    public function __destruct()
    {
        try {
            $this->in->close();
        } catch (Channel\Error\Closed $closed) {
            // @ignoreException
        }
    }

    private function create(object $object, string $interface): object
    {
        if (array_key_exists($interface, self::KNOWN_INTERFACE) === self::HASNT_PROXYABLE_INTERFACE) {
            throw NonExistentInterface::create($interface);
        }

        if ($this->registry->hasByInterface($interface)) {
            $instance = $this->registry->getByInterface($interface);
        } else {
            $instance = $this->registry->create($object, $interface);
        }

        if ($this->counterCreate instanceof Counters) {
            $this->counterCreate->counter(new Label('class', $instance->class()), new Label('interface', $interface))->incr();
        }

        /** @psalm-suppress InvalidStringClass */
        return $instance->create();
    }

    private function setUpHandlers(): void
    {
        $this->factory->channel($this->in)->flatMap(static fn (object $message): Observable => observableFromArray($message instanceof Parcel ? iteratorOrArrayToArray($message->messages()) : [$message]))->subscribe(function (object $message): void {
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

            $this->loop->addTimer(self::DESTRUCT_DEFERENCE_SECONDS, function () use ($message): void {
                $this->handleDestruct($message);
            });
        });
    }

    private function handleExistence(Existence $existence): void
    {
        if (! $this->registry->hasByHash($existence->hash())) {
            return;
        }

        $instance = $this->registry->getByHash($existence->hash());
        $instance->reference($existence->objectHash());
    }

    private function handleNotify(Notify $notify): void
    {
        if (! $this->registry->hasByHash($notify->hash()) && ($notify->link() === null || ! $this->registry->hasByHash($notify->link()->rootHash()))) {
            return;
        }

        if ($notify->link() instanceof Link) {
            $object = $this->followChain($notify->link());

            if ($object === null) {
                return;
            }

            $interface = $this->getInterfaceForOutcome($object);

            if ($interface === null) {
                return;
            }

            $instance = new Instance($object, $interface, FALSE_, $this->in);
        } else {
            $instance = $this->registry->getByHash($notify->hash());
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
        if (! $this->registry->hasByHash($call->hash()) && ($call->link() === null || ! $this->registry->hasByHash($call->link()->rootHash()))) {
            return;
        }

        if ($call->link() instanceof Link) {
            $object = $this->followChain($call->link());

            if ($object === null) {
                return;
            }

            $interface = $this->getInterfaceForOutcome($object);

            if ($interface === null) {
                return;
            }

            $instance = new Instance($object, $interface, FALSE_, $this->in);
        } else {
            $instance = $this->registry->getByHash($call->hash());
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

        if ($outcome instanceof Observable) {
            $outcome = $outcome->toArray()->toPromise();
        }

        if ($outcome instanceof PromiseInterface) {
            /**
             * @param mixed $outcome
             *
             * @psalm-suppress MissingClosureParamType
             */
            $forwardCallable = static function ($outcome) use ($call): void {
                $call->channel()->send($outcome);
            };
            $outcome->then($forwardCallable, $forwardCallable);

            return;
        }

        $call->channel()->send($outcome);
    }

    private function handleDestruct(Destruct $destruct): void
    {
        if (! $this->registry->hasByHash($destruct->hash())) {
            return;
        }

        $instance = $this->registry->getByHash($destruct->hash());
        $count    = $instance->dereference($destruct->objectHash());

        $this->countDestruct($instance);

        if ($instance->isLocked()) {
            return;
        }

        if ($count !== ZERO) {
            return;
        }

        $this->registry->dropByHash($destruct->hash());
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

        if (! $this->registry->hasByHash($link->hash())) {
            return null;
        }

        /** @phpstan-ignore-next-line */
        $result = $this->registry->getByHash($link->hash())->object()->{$link->method()}(...$link->args());
        $this->countHandledChain($result);
        /** @phpstan-ignore-next-line */
        foreach ($chain as $link) {
            /** @phpstan-ignore-next-line */
            $result = $result->{$link->method()}(...$link->args());
            $this->countHandledChain($result);
        }

        return $result;
    }

    private function countHandledChain(object $object): void
    {
        $interface = $this->getInterfaceForOutcome($object);
        if ($interface === null) {
            return;
        }

        if ($this->counterCreate instanceof Counters) {
            $this->counterCreate->counter(new Label('class', get_class($object)), new Label('interface', $interface))->incr();
        }

        if ($this->counterCall instanceof Counters) {
            $this->counterCall->counter(new Label('class', get_class($object)), new Label('interface', $interface))->incr();
        }

        if (! ($this->counterDestruct instanceof Counters)) {
            return;
        }

        $this->counterDestruct->counter(new Label('class', get_class($object)), new Label('interface', $interface))->incr();
    }
}
