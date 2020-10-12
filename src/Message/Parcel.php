<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Message;

use function array_merge;

final class Parcel
{
    /** @var array<Call|Existence|Notify> */
    private array $messages = [];

    /** @var array<Destruct> */
    private array $destructions = [];

    public function call(Call $call): void
    {
        $this->messages[] = $call;
    }

    public function existence(Existence $existence): void
    {
        $this->messages[] = $existence;
    }

    public function notify(Notify $notify): void
    {
        $this->messages[] = $notify;
    }

    public function destruct(Destruct $destruct): void
    {
        $this->destructions[] = $destruct;
    }

    /**
     * @return iterable<Call|Destruct|Existence|Notify>
     */
    public function messages(): iterable
    {
        yield from array_merge($this->messages, $this->destructions);
    }
}
