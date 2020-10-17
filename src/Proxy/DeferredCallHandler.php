<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Proxy;

use parallel\Channel;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Destruct;
use ReactParallel\ObjectProxy\Message\Existence;
use ReactParallel\ObjectProxy\Message\Notify;
use ReactParallel\ObjectProxy\Message\Parcel;

final class DeferredCallHandler
{
    private Parcel $parcel;

    final public function __construct()
    {
        $this->parcel = new Parcel();
    }

    public function call(Call $call): void
    {
        $this->parcel->call($call);
    }

    public function existence(Existence $existence): void
    {
        $this->parcel->existence($existence);
    }

    public function notify(Notify $notify): void
    {
        $this->parcel->notify($notify);
    }

    public function destruct(Destruct $destruct): void
    {
        $this->parcel->destruct($destruct);
    }

    public function commit(Channel $out): void
    {
        $out->send($this->parcel);
        $this->parcel = new Parcel();
    }
}
