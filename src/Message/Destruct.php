<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Message;

final class Destruct
{
    private string $channel;
    private string $interface;

    public function __construct(string $channel, string $interface)
    {
        $this->channel   = $channel;
        $this->interface = $interface;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function interface(): string
    {
        return $this->interface;
    }
}
