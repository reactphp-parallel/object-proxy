<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer;

final class Proxiers
{
    private InterfaceProxier $direct;
    private DeferredInterfaceProxier $deferred;
    private NoPromisesInterfacer $noPromise;

    public function __construct(InterfaceProxier $direct, DeferredInterfaceProxier $deferred, NoPromisesInterfacer $noPromise)
    {
        $this->direct    = $direct;
        $this->deferred  = $deferred;
        $this->noPromise = $noPromise;
    }

    public function direct(): InterfaceProxier
    {
        return $this->direct;
    }

    public function deferred(): DeferredInterfaceProxier
    {
        return $this->deferred;
    }

    public function noPromise(): NoPromisesInterfacer
    {
        return $this->noPromise;
    }
}
