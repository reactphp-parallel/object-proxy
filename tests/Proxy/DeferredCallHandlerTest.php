<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Proxy;

use parallel\Channel;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Destruct;
use ReactParallel\ObjectProxy\Message\Existence;
use ReactParallel\ObjectProxy\Message\Notify;
use ReactParallel\ObjectProxy\Message\Parcel;
use ReactParallel\ObjectProxy\Proxy\DeferredCallHandler;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;

use function array_map;
use function iterator_to_array;

final class DeferredCallHandlerTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function order(): void
    {
        $channel   = new Channel(1);
        $dch       = new DeferredCallHandler();
        $existence = new Existence('hash', 'objectHash');
        $call      = new Call(new Channel(1), 'hash', 'objectHash', 'method', []);
        $notify    = new Notify('hash', 'objectHash', 'method', []);
        $destruct  = new Destruct('hash', 'objectHash');
        $dch->destruct($destruct);
        $dch->existence($existence);
        $dch->notify($notify);
        $dch->call($call);

        $dch->commit($channel);

        $parcel = $channel->recv();
        self::assertInstanceOf(Parcel::class, $parcel);
        self::assertSame(
            [Existence::class, Notify::class, Call::class, Destruct::class],
            // @phpstan-ignore-next-line
            array_map('get_class', iterator_to_array($parcel->messages()))
        );
    }
}
