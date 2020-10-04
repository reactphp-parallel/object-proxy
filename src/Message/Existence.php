<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Message;

final class Existence implements Message
{
    private string $hash;
    private string $objectHash;

    public function __construct(string $hash, string $objectHash)
    {
        $this->hash       = $hash;
        $this->objectHash = $objectHash;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function objectHash(): string
    {
        return $this->objectHash;
    }
}
