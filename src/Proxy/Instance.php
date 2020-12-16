<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Proxy;

use parallel\Channel;
use ReactParallel\ObjectProxy\Generated\ProxyList;

use function count;
use function get_class;
use function spl_object_hash;

final class Instance extends ProxyList
{
    private object $object;
    private string $class;
    private string $interface;
    private Channel $in;

    /** @var array<string> */
    private array $references = [];

    private bool $locked;
    private string $hash;

    /** @phpstan-ignore-next-line */
    public function __construct(object $object, string $interface, bool $locked, Channel $in, ?string $hash = null)
    {
        $this->object    = $object;
        $this->class     = get_class($object);
        $this->interface = $interface;
        $this->locked    = $locked;
        $this->in        = $in;
        $this->hash      = $hash ?? $this->class . '___' . spl_object_hash($object);
    }

    public function object(): object
    {
        return $this->object;
    }

    public function class(): string
    {
        return $this->class;
    }

    public function interface(): string
    {
        return $this->interface;
    }

    public function reference(string $hash): void
    {
        $this->references[$hash] = $hash;
    }

    public function dereference(string $hash): int
    {
        unset($this->references[$hash]);

        return count($this->references);
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function create(): object
    {
        /**
         * @psalm-suppress EmptyArrayAccess
         */
        $class = self::KNOWN_INTERFACE[$this->interface]['direct'];

        /** @psalm-suppress InvalidStringClass */
        return new $class($this->in, $this->hash);
    }
}
