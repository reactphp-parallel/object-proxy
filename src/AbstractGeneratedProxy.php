<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use React\Datagram\Factory;
use React\Datagram\Socket;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\UnixConnector;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Destruct;

use function serialize;

abstract class AbstractGeneratedProxy
{
    private Channel $out;
    private string $port;

    final public function __construct(string $out, string $port)
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

        $connector = new UnixConnector($loop);
        $connector->connect($this->port)->then(function (ConnectionInterface $connection) use ($interface) {
            $connection->end(serialize(new Destruct((string) $this->out, $interface)));
        });

        $loop->run();
    }
}
