<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Proxy;

use parallel\Channel;
use ReactParallel\ObjectProxy\Message\Destruct;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Registry;
use WyriHaximus\Metrics\Registry\Counters;

final class DestructionHandler
{
    private ?Counters $counter = null;

    public function withMetrics(Registry $registry): self
    {
        $self          = clone $this;
        $self->counter = $registry->counter(
            'react_parallel_object_proxy_destruct',
            'Number of destroyed proxies by the garbage collector',
            new Label\Name('interface'),
        );

        return $self;
    }

    public function __invoke(Destruct $destruct): void
    {
        try {
            Channel::open($destruct->channel())->close();
        } catch (Channel\Error\Existence $existence) {
            // @ignoreException
        }

        if (! ($this->counter instanceof Counters)) {
            return;
        }

        $this->counter->counter(new Label('interface', $destruct->interface()))->incr();
    }
}
