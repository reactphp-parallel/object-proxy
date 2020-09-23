<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Message;

use parallel\Channel;
use ReactParallel\ObjectProxy\Message\Call;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;

use function time;

final class CallTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function getters(): void
    {
        $channel = new Channel(1);
        $method  = 'hammer';
        $args    = [time()];

        $call = new Call($channel, $method, $args);

        self::assertSame($channel, $call->channel());
        self::assertSame($method, $call->method());
        self::assertSame($args, $call->args());
    }
}
