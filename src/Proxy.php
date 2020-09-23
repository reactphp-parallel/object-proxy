<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Generated\ProxyList;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Proxy\CallHandler;
use Rx\Observable;

use function array_key_exists;
use function spl_object_hash;

final class Proxy extends ProxyList
{
    private const HASNT_PROXYABLE_INTERFACE = false;

    private Channel $output;
    private Observable $outputStream;
    private CallHandler $callHandler;

    public function __construct(Factory $factory)
    {
        $this->output       = new Channel(Channel::Infinite);
        $this->outputStream = $factory->streams()->channel($this->output)->share();
        $this->callHandler  = new CallHandler($this);
    }

    public function has(string $interface): bool
    {
        return array_key_exists($interface, self::KNOWN_INTERFACE);
    }

    public function create(object $object, string $interface): object
    {
        if ($this->has($interface) === self::HASNT_PROXYABLE_INTERFACE) {
            throw NonExistentInterface::create($interface);
        }

        $hash = spl_object_hash($object);
        $this->outputStream->filter(static fn (Call $call): bool => $call->hash() === $hash)->subscribe(
            ($this->callHandler)($object, $interface)
        );

        $class = self::KNOWN_INTERFACE[$interface];

        /** @psalm-suppress InvalidStringClass */
        return new $class($this->output, $hash);
    }
}
