<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy;

use parallel\Channel;
use ReactParallel\ObjectProxy\Message\Call;

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
        $this->out->send(new Call(
            $input,
            $this->hash,
            $method,
            $args,
        ));

        $result = $input->recv();
        $input->close();

        return $result;
    }
}
