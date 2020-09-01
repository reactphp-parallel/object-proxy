<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy;

use Psr\Log\LoggerInterface;
use ReactParallel\ObjectProxy\NonExistentInterface;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;

final class NonExistentInterfaceTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function create(): void
    {
        $exception = NonExistentInterface::create(LoggerInterface::class);

        self::assertSame(LoggerInterface::class, $exception->interface());
    }
}
