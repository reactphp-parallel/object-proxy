<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Configuration\Metrics;

final class Configuration
{
    private Factory $factory;
    private ?Metrics $metrics = null;

    public function __construct(Factory $factory)
    {
        $this->factory = $factory;
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
}
