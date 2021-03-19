<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use React\Promise\PromiseInterface;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Link;
use ReactParallel\ObjectProxy\Message\Notify;
use ReactParallel\ObjectProxy\Proxy\DeferredCallHandler;
use Throwable;

use function array_key_exists;
use function bin2hex;
use function random_bytes;
use function React\Promise\reject;
use function React\Promise\resolve;
use function spl_object_hash;

abstract class AbstractGeneratedDeferredProxy
{
    private ProxyListInterface $proxyList;
    private Channel $out;
    private string $hash;
    private Link $link;
    private DeferredCallHandler $deferredCallHandler;

    final public function __construct(ProxyListInterface $proxyList, Channel $out, DeferredCallHandler $deferredCallHandler, string $hash, Link $link)
    {
        $this->proxyList           = $proxyList;
        $this->out                 = $out;
        $this->deferredCallHandler = $deferredCallHandler;
        $this->hash                = $hash;
        $this->link                = $link;
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
            $this->link,
        );

        $this->deferredCallHandler->call($call);
        $this->deferredCallHandler->commit($this->out);

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
        if (! array_key_exists($interface, $this->proxyList->knownInterfaces())) {
            return $this->proxyCallToMainThread($method, $args);
        }

        /** @psalm-suppress EmptyArrayAccess */
        $class = $this->proxyList->knownInterfaces()[$interface]['deferred'];

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
                $this->link,
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
            $this->link,
        );

        $this->deferredCallHandler->notify($notify);
        $this->deferredCallHandler->commit($this->out);
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
            $this->link,
        );
        $this->deferredCallHandler->notify($notify);
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
