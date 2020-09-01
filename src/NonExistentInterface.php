<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use Exception;

/** @psalm-suppress MissingConstructor */
final class NonExistentInterface extends Exception
{
    private string $interface;

    public static function create(string $interface): self
    {
        $self            = new self('Given string isn\'t an interface');
        $self->interface = $interface;

        return $self;
    }

    public function interface(): string
    {
        return $this->interface;
    }
}
