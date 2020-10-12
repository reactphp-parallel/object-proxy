<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Message;

use parallel\Channel;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Destruct;
use ReactParallel\ObjectProxy\Message\Existence;
use ReactParallel\ObjectProxy\Message\Parcel;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;

use function bin2hex;
use function random_bytes;
use function time;
use function WyriHaximus\iteratorOrArrayToArray;

final class ParcelTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function getters(): void
    {
        $destruct  = new Destruct(bin2hex(random_bytes(1024)), bin2hex(random_bytes(1024)));
        $call      = new Call(new Channel(1), bin2hex(random_bytes(1024)), bin2hex(random_bytes(1024)), 'hammer', [time()]);
        $existence = new Existence(bin2hex(random_bytes(1024)), bin2hex(random_bytes(1024)));

        $parcel = new Parcel();
        $parcel->destruct($destruct);
        $parcel->call($call);
        $parcel->existence($existence);

        self::assertSame([$call, $existence, $destruct], iteratorOrArrayToArray($parcel->messages()));
    }
}
