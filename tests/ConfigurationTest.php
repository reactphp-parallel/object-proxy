<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy;

use React\EventLoop\StreamSelectLoop;
use ReactParallel\Factory as ParallelFactory;
use ReactParallel\ObjectProxy\Configuration;
use ReactParallel\ObjectProxy\ProxyListInterface;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;

final class ConfigurationTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function proxyList(): void
    {
        $loop              = new StreamSelectLoop();
        $parallelFactory   = new ParallelFactory($loop);
        $baseConfiguration = new Configuration($parallelFactory);
        $proxyList         = new class () implements ProxyListInterface {
            public function has(string $interface): bool
            {
                return false;
            }

            /**
             * @inheritDoc
             */
            public function get(string $interface): array
            {
                return [];
            }

            /**
             * @inheritDoc
             */
            public function interfaces(): iterable
            {
                yield from [];
            }

            /**
             * @inheritDoc
             */
            public function noPromiseKnownInterfaces(): array
            {
                return [];
            }
        };

        $clonedConfiguration = $baseConfiguration->withProxyList($proxyList);

        self::assertNotSame($proxyList, $baseConfiguration->proxyList());
        self::assertNotSame($baseConfiguration, $clonedConfiguration);
        self::assertSame($proxyList, $clonedConfiguration->proxyList());
    }
}
