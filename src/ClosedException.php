<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use RuntimeException;

final class ClosedException extends RuntimeException
{
    public static function create(): self
    {
        return new self('Proxy has been closed previously and cannot be used anymore');
    }
}
