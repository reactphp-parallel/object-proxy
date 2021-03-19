<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Configuration\Metrics;
use ReactParallel\ObjectProxy\Generated\ProxyList;

final class Configuration
{
    private Factory $factory;
    private ProxyListInterface $proxyList;
    private ?Metrics $metrics = null;

    public function __construct(Factory $factory)
    {
        $this->factory   = $factory;
        $this->proxyList = new ProxyList();
    }

    public function factory(): Factory
    {
        return $this->factory;
    }

    public function withMetrics(Metrics $metrics): self
    {
        $clone          = clone $this;
        $clone->metrics = $metrics;

        return $clone;
    }

    /** @phpstan-ignore-next-line */
    public function metrics(): ?Metrics
    {
        return $this->metrics;
    }

    public function withProxyList(ProxyListInterface $proxyList): self
    {
        $clone            = clone $this;
        $clone->proxyList = $proxyList;

        return $clone;
    }

    public function proxyList(): ProxyListInterface
    {
        return $this->proxyList;
    }
}
