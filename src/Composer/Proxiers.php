<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer;

final class Proxiers
{
    private InterfaceProxier $direct;
    private DeferredInterfaceProxier $deferred;

    public function __construct(InterfaceProxier $direct, DeferredInterfaceProxier $deferred)
    {
        $this->direct   = $direct;
        $this->deferred = $deferred;
    }

    public function direct(): InterfaceProxier
    {
        return $this->direct;
    }

    public function deferred(): DeferredInterfaceProxier
    {
        return $this->deferred;
    }
}
