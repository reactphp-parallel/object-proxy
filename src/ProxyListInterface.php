<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

interface ProxyListInterface
{
    /**
     * @return array<string, array<string, string>>
     */
    public function knownInterfaces(): array;

    /**
     * @return array<string, string>
     */
    public function noPromiseKnownInterfaces(): array;
}
