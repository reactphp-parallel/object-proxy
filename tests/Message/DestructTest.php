<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Message;

use ReactParallel\ObjectProxy\Message\Destruct;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;

use function bin2hex;
use function random_bytes;

final class DestructTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function getters(): void
    {
        $hash       = bin2hex(random_bytes(1024));
        $objectHash = bin2hex(random_bytes(1024));

        $call = new Destruct($hash, $objectHash);

        self::assertSame($hash, $call->hash());
        self::assertSame($objectHash, $call->objectHash());
    }
}
