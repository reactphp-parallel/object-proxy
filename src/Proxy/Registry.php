<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Proxy;

use parallel\Channel;
use ReactParallel\ObjectProxy\ProxyListInterface;

use function array_key_exists;
use function bin2hex;
use function random_bytes;

use const WyriHaximus\Constants\Boolean\FALSE_;
use const WyriHaximus\Constants\Boolean\TRUE_;

final class Registry
{
    private const RANDOM_BYTES_LENGTH = 13;

    private ProxyListInterface $proxyList;
    private Channel $in;

    /** @var array<string, Instance> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $shared = [];

    public function __construct(ProxyListInterface $proxyList, Channel $in, Instance ...$instances)
    {
        $this->proxyList = $proxyList;
        $this->in        = $in;
        foreach ($instances as $instance) {
            $this->instances[$instance->hash()] = $instance;
            if (! $instance->isLocked()) {
                continue;
            }

            $this->shared[$instance->interface()] = $instance->hash();
        }
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
        $instance                           = new Instance($this->proxyList, $object, $interface, FALSE_, $this->in);
        $this->instances[$instance->hash()] = $instance;

        return $instance;
    }

    public function share(object $object, string $interface): Instance
    {
        $instance                           = new Instance($this->proxyList, $object, $interface, TRUE_, $this->in);
        $this->instances[$instance->hash()] = $instance;
        $this->shared[$interface]           = $instance->hash();

        return $instance;
    }

    public function thread(object $object, string $interface, Channel $in): Instance
    {
        $instance                           = new Instance($this->proxyList, $object, $interface, TRUE_, $in, bin2hex(random_bytes(self::RANDOM_BYTES_LENGTH)));
        $this->instances[$instance->hash()] = $instance;
        $this->shared[$interface]           = $instance->hash();

        return $instance;
    }
}
