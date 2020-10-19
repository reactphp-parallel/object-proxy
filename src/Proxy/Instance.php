<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Proxy;

use function count;
use function get_class;

final class Instance
{
    private object $object;
    private string $class;
    private string $interface;

    /** @var array<string> */
    private array $references = [];

    private bool $locked;

    public function __construct(object $object, string $interface, bool $locked)
    {
        $this->object    = $object;
        $this->class     = get_class($object);
        $this->interface = $interface;
        $this->locked    = $locked;
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
}
