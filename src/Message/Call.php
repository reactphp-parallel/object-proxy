<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Message;

use parallel\Channel;

final class Call
{
    private Channel $channel;
    private string $method;

    /** @var mixed[] */
    private array $args;

    /**
     * @param mixed[] $args
     */
    public function __construct(Channel $channel, string $method, array $args)
    {
        $this->channel = $channel;
        $this->method  = $method;
        $this->args    = $args;
    }

    public function channel(): Channel
    {
        return $this->channel;
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
