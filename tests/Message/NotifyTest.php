<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Message;

use ReactParallel\ObjectProxy\Message\Notify;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;

use function bin2hex;
use function random_bytes;
use function time;

final class NotifyTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function getters(): void
    {
        $hash       = bin2hex(random_bytes(1024));
        $objectHash = bin2hex(random_bytes(1024));
        $method     = 'hammer';
        $args       = [time()];

        $call = new Notify($hash, $objectHash, $method, $args, null);

        self::assertSame($hash, $call->hash());
        self::assertSame($objectHash, $call->objectHash());
        self::assertSame($method, $call->method());
        self::assertSame($args, $call->args());
    }
}
