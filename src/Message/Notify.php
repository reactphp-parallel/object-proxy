<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Message;

final class Notify
{
    private string $hash;
    private string $objectHash;

    private string $method;

    /** @var mixed[] */
    private array $args;

    /**
     * @param mixed[] $args
     */
    public function __construct(string $hash, string $objectHash, string $method, array $args)
    {
        $this->hash       = $hash;
        $this->objectHash = $objectHash;
        $this->method     = $method;
        $this->args       = $args;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function objectHash(): string
    {
        return $this->objectHash;
    }

    public function method(): string
    {
        return $this->method;
    }

    /**
     * @return mixed[]
     */
    public function args(): array
    {
        return $this->args;
    }
}
