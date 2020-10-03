<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Message;

final class Destruct
{
    private string $hash;
    private string $interface;

    public function __construct(string $hash, string $interface)
    {
        $this->hash      = $hash;
        $this->interface = $interface;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function interface(): string
    {
        return $this->interface;
    }
}
