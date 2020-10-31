<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Message;

use parallel\Channel;

final class Call
{
    private Channel $channel;

    private string $hash;
    private string $objectHash;

    private string $method;

    /** @var mixed[] */
    private array $args;

    private ?object $link;

    /**
     * @param mixed[] $args
     */
    public function __construct(Channel $channel, string $hash, string $objectHash, string $method, array $args, ?Link $link)
    {
        $this->channel    = $channel;
        $this->hash       = $hash;
        $this->objectHash = $objectHash;
        $this->method     = $method;
        $this->args       = $args;
        $this->link       = $link;
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

    /**
     * @psalm-suppress MoreSpecificReturnType
     */
    public function link(): ?Link
    {
        /**
         * Have to do this because ext-parallel blows if we set the use Link as typed property
         *
         * @psalm-suppress LessSpecificReturnStatement
         */
        return $this->link;
    }
}
