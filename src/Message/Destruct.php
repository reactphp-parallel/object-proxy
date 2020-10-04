<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Message;

final class Destruct
{
    private string $hash;
    private string $objectHash;
    private string $interface;

    public function __construct(string $hash, string $objectHash, string $interface)
    {
        $this->hash       = $hash;
        $this->objectHash = $objectHash;
        $this->interface  = $interface;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function objectHash(): string
    {
        return $this->objectHash;
    }

    public function interface(): string
    {
        return $this->interface;
    }
}
