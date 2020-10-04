<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Message\Destruct;

use function spl_object_hash;

abstract class AbstractGeneratedProxy
{
    private Channel $out;
    private string $hash;

    final public function __construct(Channel $out, string $hash)
    {
        $this->out  = $out;
        $this->hash = $hash;
    }

    /**
     * @param mixed[] $args
     *
     * @return mixed
     */
    final protected function proxyCallToMainThread(string $method, array $args)
    {
        $input = new Channel(1);
        $call  = new Call(
            $input,
            $this->hash,
            spl_object_hash($this),
            $method,
            $args,
        );
        $this->out->send($call);
        $result = $input->recv();
        $input->close();

        return $result;
    }

    final protected function notifyMainThreadAboutDestruction(): void
    {
        try {
            $this->out->send(new Destruct($this->hash, spl_object_hash($this)));
        } catch (Channel\Error\Closed $closed) {
            // @ignoreException
        }
    }
}
