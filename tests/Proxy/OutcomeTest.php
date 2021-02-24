<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Proxy;

use Exception;
use ReactParallel\ObjectProxy\Proxy\Outcome;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;

use function get_class;

final class OutcomeTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function result(): void
    {
        $result  = true;
        $outcome = new Outcome($result);

        self::assertTrue($outcome->outcome());
    }

    /**
     * @test
     */
    public function throwable(): void
    {
        $throwable = new Exception('Call went boom!');
        self::expectException(get_class($throwable));
        self::expectExceptionMessage($throwable->getMessage());
        $outcome = new Outcome($throwable);

        $outcome->outcome();
    }
}
