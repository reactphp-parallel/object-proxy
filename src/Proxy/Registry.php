<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Proxy;

use parallel\Channel;
use ReactParallel\ObjectProxy\Generated\ProxyList;

use function array_key_exists;

use const WyriHaximus\Constants\Boolean\FALSE_;

final class Registry extends ProxyList
{
    private Channel $in;

    /** @var array<string, Instance> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $shared = [];

    public function __construct(Channel $in)
    {
        $this->in = $in;
    }

    public function hasByInterface(string $interface): bool
    {
        return array_key_exists($interface, $this->shared);
    }

    public function hasByHash(string $hash): bool
    {
        return array_key_exists($hash, $this->instances);
    }

    public function getByInterface(string $interface): Instance
    {
        return $this->instances[$this->shared[$interface]];
    }

    public function getByHash(string $hash): Instance
    {
        return $this->instances[$hash];
    }

    public function dropByHash(string $hash): void
    {
        unset($this->instances[$hash]);
    }

    public function create(object $object, string $interface): Instance
    {
        $instance                           = new Instance($object, $interface, FALSE_, $this->in);
        $this->instances[$instance->hash()] = $instance;

        return $instance;
    }

    public function share(object $object, string $interface): Instance
    {
        $instance                           = new Instance($object, $interface, true, $this->in);
        $this->instances[$instance->hash()] = $instance;
        $this->shared[$interface]           = $instance->hash();

        return $instance;
    }
}
