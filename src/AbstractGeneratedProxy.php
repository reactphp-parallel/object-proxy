<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use ReactParallel\ObjectProxy\Generated\ProxyList;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Destruct;
use ReactParallel\ObjectProxy\Message\Existence;
use ReactParallel\ObjectProxy\Message\Notify;
use ReactParallel\ObjectProxy\Proxy\DeferredCallHandler;

use function spl_object_hash;

abstract class AbstractGeneratedProxy extends ProxyList
{
    private Channel $out;
    private string $hash;
    private ?DeferredCallHandler $deferredCallHandler = null;

    final public function __construct(Channel $out, string $hash)
    {
        $this->out  = $out;
        $this->hash = $hash;
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
        );

        if ($this->deferredCallHandler instanceof DeferredCallHandler) {
            $this->deferredCallHandler->call($call);
            $this->deferredCallHandler->commit($this->out);
        } else {
            $this->out->send($call);
        }

        $result = $input->recv();
        $input->close();

        return $result;
    }

    /**
     * @param mixed[] $args
     *
     * @return mixed|void
     */
    final protected function deferCallToMainThread(string $method, array $args)
    {
        $input = new Channel(1);
        $call  = new Call(
            $input,
            $this->hash,
            spl_object_hash($this),
            $method,
            $args,
        );

        if ($this->deferredCallHandler instanceof DeferredCallHandler) {
            $this->deferredCallHandler->call($call);
            $this->deferredCallHandler->commit($this->out);
        } else {
            $this->out->send($call);
        }

        $result = $input->recv();
        $input->close();

        return $result;
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
}
