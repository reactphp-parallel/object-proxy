<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use React\Promise\PromiseInterface;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Destruct;
use ReactParallel\ObjectProxy\Message\Existence;
use ReactParallel\ObjectProxy\Message\Link;
use ReactParallel\ObjectProxy\Message\Notify;
use ReactParallel\ObjectProxy\Proxy\DeferredCallHandler;
use Throwable;

use function bin2hex;
use function random_bytes;
use function React\Promise\reject;
use function React\Promise\resolve;
use function spl_object_hash;

abstract class AbstractGeneratedProxy
{
    private ProxyListInterface $proxyList;
    private Channel $out;
    private string $hash;
    private ?DeferredCallHandler $deferredCallHandler = null;

    final public function __construct(ProxyListInterface $proxyList, Channel $out, string $hash)
    {
        $this->proxyList = $proxyList;
        $this->out       = $out;
        $this->hash      = $hash;
    }

    /**
     * @param mixed[] $args
     *
     * @return mixed|void
     */
    final protected function proxyCallToMainThread(string $method, array $args)
    {
        $input = new Channel(1);
        $call  = new Call(
            $input,
            $this->hash,
            spl_object_hash($this),
            $method,
            $args,
            null,
        );

        if ($this->deferredCallHandler instanceof DeferredCallHandler) {
            $this->deferredCallHandler->call($call);
            $this->deferredCallHandler->commit($this->out);
        } else {
            $this->out->send($call);
        }

        $result = $input->recv()->outcome();
        $input->close();

        return $result;
    }

    /**
     * @param mixed[] $args
     *
     * @return mixed|void
     */
    final protected function createDeferredProxy(string $method, array $args, string $interface)
    {
        if (! $this->proxyList->has($interface) || ! ($this->deferredCallHandler instanceof DeferredCallHandler)) {
            return $this->proxyCallToMainThread($method, $args);
        }

        /** @psalm-suppress EmptyArrayAccess */
        $class = $this->proxyList->get($interface)->deferred();

        /** @psalm-suppress InvalidStringClass */
        return new $class(
            $this->proxyList,
            $this->out,
            $this->deferredCallHandler,
            static::class . '___' . bin2hex(random_bytes(13)),
            new Link(
                $this->hash,
                spl_object_hash($this),
                $method,
                $args,
                null,
            )
        );
    }

    /**
     * @param mixed[] $args
     */
    final protected function proxyNotifyMainThread(string $method, array $args): void
    {
        $notify = new Notify(
            $this->hash,
            spl_object_hash($this),
            $method,
            $args,
            null,
        );
        if ($this->deferredCallHandler instanceof DeferredCallHandler) {
            $this->deferredCallHandler->notify($notify);
            $this->deferredCallHandler->commit($this->out);

            return;
        }

        $this->out->send($notify);
    }

    /**
     * @param mixed[] $args
     */
    final protected function deferNotifyMainThread(string $method, array $args): void
    {
        $notify = new Notify(
            $this->hash,
            spl_object_hash($this),
            $method,
            $args,
            null,
        );
        if ($this->deferredCallHandler instanceof DeferredCallHandler) {
            $this->deferredCallHandler->notify($notify);

            return;
        }

        $this->out->send($notify);
    }

    final protected function notifyMainThreadAboutDestruction(): void
    {
        $destruct = new Destruct($this->hash, spl_object_hash($this));
        if ($this->deferredCallHandler instanceof DeferredCallHandler) {
            $this->deferredCallHandler->destruct($destruct);

            return;
        }

        try {
            $this->out->send($destruct);
        } catch (Channel\Error\Closed $closed) {
            // @ignoreException
        }
    }

    final public function notifyMainThreadAboutOurExistence(): void
    {
        $existence = new Existence($this->hash, spl_object_hash($this));
        if ($this->deferredCallHandler instanceof DeferredCallHandler) {
            $this->deferredCallHandler->existence($existence);
            $this->deferredCallHandler->commit($this->out);

            return;
        }

        try {
            $this->out->send($existence);
        } catch (Channel\Error\Closed $closed) {
            // @ignoreException
        }
    }

    final public function setDeferredCallHandler(DeferredCallHandler $deferredCallHandler): void
    {
        $this->deferredCallHandler = $deferredCallHandler;
    }

    /**
     * @param mixed|Throwable $result
     */
    final public function detectResolvedOrRejectedPromise($result): PromiseInterface
    {
        if ($result instanceof Throwable) {
            return reject($result);
        }

        return resolve($result);
    }
}
