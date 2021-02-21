<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Proxy;

use Throwable;

use function is_string;
use function WyriHaximus\throwable_json_decode;
use function WyriHaximus\throwable_json_encode;

final class Outcome
{
    /** @var ?mixed */
    private $result = null;

    private ?string $throwable = null;

    /**
     * @param mixed|Throwable $outcome
     */
    public function __construct($outcome)
    {
        if ($outcome instanceof Throwable) {
            $this->throwable = throwable_json_encode($outcome);
        } else {
            $this->result = $outcome;
        }
    }

    /**
     * @return mixed|void
     */
    public function outcome()
    {
        if (is_string($this->throwable)) {
            throw throwable_json_decode($this->throwable);
        }

        return $this->result;
    }
}
