<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\ProxyList;

final class Proxy
{
    private string $direct;
    private string $deferred;

    public function __construct(string $direct, string $deferred)
    {
        $this->direct   = $direct;
        $this->deferred = $deferred;
    }

    public function direct(): string
    {
        return $this->direct;
    }

    public function deferred(): string
    {
        return $this->deferred;
    }
}
