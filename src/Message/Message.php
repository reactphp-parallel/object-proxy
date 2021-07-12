<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Message;

interface Message
{
    public function hash(): string;

    public function objectHash(): string;
}
