<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy;

use Exception;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\HandlerInterface;

final class ThrowingLoggerStub extends AbstractHandler implements HandlerInterface
{
    /**
     * @param array<mixed> $record
     */
    public function handle(array $record): bool
    {
        /** @phpstan-ignore-next-line */
        throw new Exception('Tree!');
    }
}
