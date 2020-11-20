<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Configuration;

use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Registry;
use WyriHaximus\Metrics\Registry\Counters;

final class Metrics
{
    private Counters $createCounter;
    private Counters $notifyCounter;
    private Counters $callCounter;
    private Counters $destructCounter;

    public function __construct(Counters $counterCreate, Counters $counterNotify, Counters $counterCall, Counters $counterDestruct)
    {
        $this->createCounter   = $counterCreate;
        $this->notifyCounter   = $counterNotify;
        $this->callCounter     = $counterCall;
        $this->destructCounter = $counterDestruct;
    }

    public static function create(Registry $registry): self
    {
        return new self(
            $registry->counter(
                'react_parallel_object_proxy_create',
                'Number of create proxies by the object proxy',
                new Label\Name('class'),
                new Label\Name('interface'),
            ),
            $registry->counter(
                'react_parallel_object_proxy_notify',
                'The number of notifications from worker threads through proxies to the main thread',
                new Label\Name('class'),
                new Label\Name('interface'),
            ),
            $registry->counter(
                'react_parallel_object_proxy_call',
                'The number of calls from worker threads through proxies to the main thread',
                new Label\Name('class'),
                new Label\Name('interface'),
            ),
            $registry->counter(
                'react_parallel_object_proxy_destruct',
                'Number of destroyed proxies by the garbage collector',
                new Label\Name('class'),
                new Label\Name('interface'),
            ),
        );
    }

    public function createCounter(): Counters
    {
        return $this->createCounter;
    }

    public function notifyCounter(): Counters
    {
        return $this->notifyCounter;
    }

    public function callCounter(): Counters
    {
        return $this->callCounter;
    }

    public function destructCounter(): Counters
    {
        return $this->destructCounter;
    }
}
