<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Composer;

use React\Promise\PromiseInterface;
use Rx\Observable;

interface EdgeCaseInterface
{
    /**
     * @return Observable<SameClassInTheSameNamespace>
     *
     * @phpstan-ignore-next-line
     */
    public function inception(EdgeCaseInterface $edgeCase): Observable;

    /**
     * @return PromiseInterface<SameClassInTheSameNamespace>
     *
     * @phpstan-ignore-next-line
     */
    public function noPromises(EdgeCaseInterface $edgeCase): PromiseInterface;
}
