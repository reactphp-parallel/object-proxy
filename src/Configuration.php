<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use ReactParallel\Factory;

final class Configuration
{
    private Factory $factory;

    public function __construct(Factory $factory)
    {
        $this->factory = $factory;
    }

    public function factory(): Factory
    {
        return $this->factory;
    }
}
