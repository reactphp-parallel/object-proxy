<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Proxy;

use parallel\Channel;
use ReactParallel\ObjectProxy\Message\Destruct;
use ReactParallel\ObjectProxy\Message\Existence;
use ReactParallel\ObjectProxy\Message\Notify;
use ReactParallel\ObjectProxy\Message\Parcel;

final class DeferredCallHandler
{
    private Channel $out;
    private Parcel $parcel;

    final public function __construct(Channel $out)
    {
        $this->out  = $out;
        $this->parcel = new Parcel();
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

    public function commit(): void
    {
        try {
            $this->out->send($this->parcel);
            $this->parcel = new Parcel();
        } catch (Channel\Error\Closed $closed) {
            // @ignoreException
        }
    }
}
