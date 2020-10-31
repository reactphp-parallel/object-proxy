<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Message;

use parallel\Channel;
use ReactParallel\ObjectProxy\Message\Call;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;

use function bin2hex;
use function random_bytes;
use function time;

final class CallTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function getters(): void
    {
        $channel    = new Channel(1);
        $hash       = bin2hex(random_bytes(1024));
        $objectHash = bin2hex(random_bytes(1024));
        $method     = 'hammer';
        $args       = [time()];

        $call = new Call($channel, $hash, $objectHash, $method, $args, null);

        self::assertSame($channel, $call->channel());
        self::assertSame($hash, $call->hash());
        self::assertSame($objectHash, $call->objectHash());
        self::assertSame($method, $call->method());
        self::assertSame($args, $call->args());
    }
}
