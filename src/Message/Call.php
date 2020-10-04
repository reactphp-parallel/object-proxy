<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Message;

use parallel\Channel;

final class Call
{
    private Channel $channel;

    private string $hash;
    private string $objectHash;
    private string $interface;

    private string $method;

    /** @var mixed[] */
    private array $args;

    /**
     * @param mixed[] $args
     */
    public function __construct(Channel $channel, string $hash, string $objectHash, string $interface, string $method, array $args)
    {
        $this->channel    = $channel;
        $this->hash       = $hash;
        $this->objectHash = $objectHash;
        $this->interface  = $interface;
        $this->method     = $method;
        $this->args       = $args;
    }

    public function channel(): Channel
    {
        return $this->channel;
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
