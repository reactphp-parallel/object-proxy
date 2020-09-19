<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Composer;

interface EdgeCaseInterface
{
    public function inception(EdgeCaseInterface $edgeCase): void;
}
