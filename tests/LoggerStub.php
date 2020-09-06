<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy;

use Monolog\Handler\AbstractHandler;
use Monolog\Handler\HandlerInterface;

final class LoggerStub extends AbstractHandler implements HandlerInterface
{
    /** @var array<mixed> */
    private array $records = [];

    /**
     * @param array<mixed> $record
     */
    public function handle(array $record): bool
    {
        $this->records[] = $record;

        return true;
    }

    /**
     * @return array<mixed>
     */
    public function records(): array
    {
        return $this->records;
    }
}
