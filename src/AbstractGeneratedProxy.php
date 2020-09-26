<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use React\Datagram\Factory;
use React\Datagram\Socket;
use React\EventLoop\StreamSelectLoop;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Destruct;

use function serialize;

abstract class AbstractGeneratedProxy
{
    private Channel $out;
    private int $port;

    final public function __construct(string $out, int $port)
    {
        $this->out  = Channel::open($out);
        $this->port = $port;
    }

    /**
     * @param mixed[] $args
     *
     * @return mixed
     */
    final protected function proxyCallToMainThread(string $method, array $args)
    {
        $input = new Channel(1);
        $this->out->send(new Call(
            $input,
            $method,
            $args,
        ));

        $result = $input->recv();
        $input->close();

        return $result;
    }

    final protected function notifyMainThreadAboutDestruction(string $interface): void
    {
        $loop    = new StreamSelectLoop();
        $factory = new Factory($loop);

        $factory->createClient('127.0.0.1:' . $this->port)->then(function (Socket $client) use ($loop, $interface): void {
            $client->on('error', static function ($e) {
                var_export([__FILE__, __LINE__, (string)$e]);
            });
            $client->send(serialize(new Destruct((string) $this->out, $interface)));
            $loop->addTimer(1, [$client, 'close']);
        });

        $loop->run();
    }
}
