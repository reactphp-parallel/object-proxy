<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy;

use Lcobucci\Clock\FrozenClock;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory as EventLoopFactory;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\ClosedException;
use ReactParallel\ObjectProxy\Configuration;
use ReactParallel\ObjectProxy\Configuration\Metrics;
use ReactParallel\ObjectProxy\Generated\WyriHaximus__Metrics_RegistryProxy;
use ReactParallel\ObjectProxy\NonExistentInterface;
use ReactParallel\ObjectProxy\Proxy;
use stdClass;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use WyriHaximus\Metrics\Configuration as MetricsConfiguration;
use WyriHaximus\Metrics\InMemory\Registry as InMemmoryRegistry;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Printer\Prometheus;
use WyriHaximus\Metrics\Registry;
use Yuloh\Container\Container;

use function count;
use function range;
use function React\Promise\all;
use function Safe\sleep;
use function strpos;
use function time;

use const WyriHaximus\Constants\Boolean\FALSE_;
use const WyriHaximus\Constants\Boolean\TRUE_;

final class ProxyTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function logger(): void
    {
        $loop        = EventLoopFactory::create();
        $factory     = new Factory($loop);
        $proxy       = new Proxy(new Configuration($factory));
        $loggerStub  = new LoggerStub();
        $logger      = new Logger(
            'stub',
            [$loggerStub],
        );
        $loggerProxy = $proxy->create($logger, LoggerInterface::class);
        $time        = time();

        $returnedTime = $this->await(
            /** @phpstan-ignore-next-line */
            $factory->call(static function (LoggerInterface $logger, int $time): int {
                $logger->debug((string) $time);

                return $time;
            }, [$loggerProxy, $time])->always(static function () use ($factory): void {
                $factory->lowLevelPool()->close();
            }),
            $loop
        );

        self::assertSame($time, $returnedTime);
    }

    /**
     * @test
     */
    public function loggerThroughContainer(): void
    {
        $loop       = EventLoopFactory::create();
        $factory    = new Factory($loop);
        $proxy      = new Proxy(new Configuration($factory));
        $loggerStub = new LoggerStub();
        $logger     = new Logger(
            'stub',
            [$loggerStub],
        );
        $container  = new Container();
        $container->set(LoggerInterface::class, static function () use ($logger): LoggerInterface {
            return $logger;
        });
        $containerProxy = $proxy->create($container, ContainerInterface::class);
        $time           = time();

        self::assertTrue($proxy->has(LoggerInterface::class));
        $returnedTime = $this->await(
            /** @phpstan-ignore-next-line */
            $factory->call(static function (ContainerInterface $container, int $time): int {
                $container->get(LoggerInterface::class)->debug((string) $time);
                unset($container);

                return $time;
            }, [$containerProxy, $time])->always(static function () use ($factory): void {
                $factory->lowLevelPool()->close();
            }),
            $loop
        );

        self::assertSame($time, $returnedTime);
    }

    /**
     * @test
     */
    public function shareUnknown(): void
    {
        self::expectException(NonExistentInterface::class);

        (new Proxy(new Configuration(new Factory(EventLoopFactory::create()))))->share(new stdClass(), 'NoordHollandsKanaal');
    }

    /**
     * @test
     */
    public function createUnknown(): void
    {
        self::expectException(NonExistentInterface::class);

        (new Proxy(new Configuration(new Factory(EventLoopFactory::create()))))->create(new stdClass(), 'NoordHollandsKanaal');
    }

    /**
     * @test
     */
    public function threadItUnknown(): void
    {
        self::expectException(NonExistentInterface::class);

        (new Proxy(new Configuration(new Factory(EventLoopFactory::create()))))->thread(new stdClass(), 'NoordHollandsKanaal');
    }

    /**
     * @test
     */
    public function metricsDestructionTesting(): void
    {
        $loop          = EventLoopFactory::create();
        $factory       = new Factory($loop);
        $configuration = MetricsConfiguration::create()->withClock(FrozenClock::fromUTC());
        $registry      = new InMemmoryRegistry($configuration);
        $proxy         = (new Proxy((new Configuration($factory))->withMetrics(Metrics::create($registry))));
        $limitedPool   = $factory->limitedPool(1);
        $registryProxy = $proxy->share($registry, Registry::class);

        $fn = static function (int $int, WyriHaximus__Metrics_RegistryProxy $registryProxy): int {
            $registryProxy->setDeferredCallHandler(new Proxy\DeferredCallHandler());
            $registryProxy->counter('counter', 'bla bla bla', new Label\Name('name'))->counter(new Label('name', 'value'))->incr();
            $registryProxy->print(new Prometheus());

            return $int;
        };

        $promises = [];
        for ($i = 0; $i < 128; $i++) {
            $promises[] = $limitedPool->run($fn, [$i, $registryProxy]);
        }

        try {
            $results = $this->await(
                // @phpstan-ignore-next-line
                all($promises)->then(static function (array $v) use ($factory): PromiseInterface {
                    return new Promise(static function (callable $resolve) use ($v, $factory): void {
                        $factory->loop()->addTimer(40, static function () use ($resolve, $v): void {
                            $resolve($v);
                        });
                    });
                })->always(static function () use ($limitedPool): void {
                    $limitedPool->kill();
                }),
                $loop
            );
            self::assertCount(count($promises), $results);
        } catch (TimeoutException $timeoutException) {
            // @ignoreException
        }

        $this->assertPrometheusOutput($registry->print(new Prometheus()));
    }

    /**
     * @test
     */
    public function metricsDestructionTestingInThread(): void
    {
        $loop             = EventLoopFactory::create();
        $factory          = new Factory($loop);
        $configuration    = MetricsConfiguration::create()->withClock(FrozenClock::fromUTC());
        $inMemoryRegistry = new InMemmoryRegistry($configuration);
        $registry         = new \WyriHaximus\Metrics\LazyRegistry\Registry();
        $proxy            = (new Proxy((new Configuration($factory))->withMetrics(Metrics::create($registry))));
        $limitedPool      = $factory->limitedPool(1);
        $registryProxy    = $proxy->thread($inMemoryRegistry, Registry::class);
        $registry->register($registryProxy); /** @phpstan-ignore-line */

        $fn = static function (int $int, WyriHaximus__Metrics_RegistryProxy $registryProxy): int {
            $registryProxy->setDeferredCallHandler(new Proxy\DeferredCallHandler());
            $registryProxy->counter('counter', 'bla bla bla', new Label\Name('name'))->counter(new Label('name', 'value'))->incr();
            $registryProxy->print(new Prometheus());

            return $int;
        };

        $promises = [];
        for ($i = 0; $i < 128; $i++) {
            $promises[] = $limitedPool->run($fn, [$i, $registryProxy]);
        }

        try {
            $results = $this->await(
                // @phpstan-ignore-next-line
                all($promises)->then(static function (array $v) use ($factory): PromiseInterface {
                    return new Promise(static function (callable $resolve) use ($v, $factory): void {
                        $factory->loop()->addTimer(40, static function () use ($resolve, $v): void {
                            $resolve($v);
                        });
                    });
                })->always(static function () use ($limitedPool, $proxy): void {
                    $proxy->close();
                    $limitedPool->kill();
                }),
                $loop
            );
            self::assertCount(count($promises), $results);
        } catch (TimeoutException $timeoutException) {
            // @ignoreException
        }

        $this->assertPrometheusOutput($registry->print(new Prometheus()));
    }

    private function assertPrometheusOutput(string $txt): void
    {
        self::assertStringContainsString('counter_total{name="value"} 128', $txt);
        self::assertStringContainsString('react_parallel_object_proxy_create_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Registry",interface="WyriHaximus\\\\Metrics\\\\Registry"} 1', $txt);
        if (strpos($txt, 'react_parallel_object_proxy_create_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Registry\\\\Counters",interface="WyriHaximus\\\\Metrics\\\\Registry\\\\Counters"} 128') !== false) {
            self::assertStringContainsString('react_parallel_object_proxy_create_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Registry\\\\Counters",interface="WyriHaximus\\\\Metrics\\\\Registry\\\\Counters"} 128', $txt);
            self::assertStringContainsString('react_parallel_object_proxy_create_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Counter",interface="WyriHaximus\\\\Metrics\\\\Counter"} 128', $txt);
            self::assertStringContainsString('react_parallel_object_proxy_notify_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Counter",interface="WyriHaximus\\\\Metrics\\\\Counter"} 128', $txt);
            self::assertStringContainsString('react_parallel_object_proxy_call_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Registry",interface="WyriHaximus\\\\Metrics\\\\Registry"} 128', $txt);
            self::assertStringContainsString('react_parallel_object_proxy_call_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Registry\\\\Counters",interface="WyriHaximus\\\\Metrics\\\\Registry\\\\Counters"} 128', $txt);
        } else {
            self::assertStringContainsString('react_parallel_object_proxy_create_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Registry\\\\Counters",interface="WyriHaximus\\\\Metrics\\\\Registry\\\\Counters"} 132', $txt);
            self::assertStringContainsString('react_parallel_object_proxy_create_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Counter",interface="WyriHaximus\\\\Metrics\\\\Counter"} 129', $txt);
            self::assertStringContainsString('react_parallel_object_proxy_notify_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Counter",interface="WyriHaximus\\\\Metrics\\\\Counter"} 129', $txt);
            self::assertStringContainsString('react_parallel_object_proxy_call_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Registry",interface="WyriHaximus\\\\Metrics\\\\Registry"} 133', $txt);
            self::assertStringContainsString('react_parallel_object_proxy_call_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Registry\\\\Counters",interface="WyriHaximus\\\\Metrics\\\\Registry\\\\Counters"} 129', $txt);
        }

        self::assertStringContainsString('react_parallel_object_proxy_destruct_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Registry\\\\Counters",interface="WyriHaximus\\\\Metrics\\\\Registry\\\\Counters"} 128', $txt);
        self::assertStringContainsString('react_parallel_object_proxy_destruct_total{class="WyriHaximus\\\\Metrics\\\\InMemory\\\\Counter",interface="WyriHaximus\\\\Metrics\\\\Counter"} 128', $txt);
    }

    /**
     * @test
     */
    public function destructionEdgeCases(): void
    {
        $loop          = EventLoopFactory::create();
        $factory       = new Factory($loop);
        $registry      = new InMemmoryRegistry(MetricsConfiguration::create()->withClock(FrozenClock::fromUTC()));
        $proxy         = (new Proxy((new Configuration($factory))->withMetrics(Metrics::create($registry))));
        $limitedPool   = $factory->limitedPool(13);
        $registryProxy = $proxy->create($registry, Registry::class);
        $fn            = static function (int $int, WyriHaximus__Metrics_RegistryProxy $registryProxy, int $sleep): int {
            $registryProxy->notifyMainThreadAboutOurExistence();
            $registryProxy->setDeferredCallHandler(new Proxy\DeferredCallHandler());
            sleep($sleep);
            $registryProxy->counter('counter', 'bla bla bla', new Label\Name('name'))->counter(new Label('name', 'value'))->incr();

            return $int;
        };

        $promises = [];
        foreach (range(0, 3) as $i) {
            $promises[] = $limitedPool->run($fn, [$i, $registryProxy, 0]);
        }

        $leet = $this->await(
            // @phpstan-ignore-next-line
            all($promises)->then(static function (array $v) use ($factory): PromiseInterface {
                return new Promise(static function (callable $resolve) use ($v, $factory): void {
                    $factory->loop()->addTimer(3, static function () use ($resolve, $v): void {
                        $resolve($v);
                    });
                });
            })->then(static function () use ($fn, $registryProxy, $limitedPool): PromiseInterface {
                return $limitedPool->run($fn, [1337, $registryProxy, 20]);
            })->always(static function () use ($limitedPool, $loop): void {
                $limitedPool->kill();
                $loop->stop();
            }),
            $loop,
            133
        );
        self::assertSame(1337, $leet);
    }

    /**
     * @test
     */
    public function destructionLocked(): void
    {
        $loop          = EventLoopFactory::create();
        $factory       = new Factory($loop);
        $registry      = new InMemmoryRegistry(MetricsConfiguration::create()->withClock(FrozenClock::fromUTC()));
        $proxy         = (new Proxy((new Configuration($factory))->withMetrics(Metrics::create($registry))));
        $limitedPool   = $factory->limitedPool(13);
        $registryProxy = $proxy->share($registry, Registry::class);
        $fn            = static function (int $int, WyriHaximus__Metrics_RegistryProxy $registryProxy, int $sleep): int {
            $registryProxy->setDeferredCallHandler(new Proxy\DeferredCallHandler());
            sleep($sleep);
            $registryProxy->counter('counter', 'bla bla bla', new Label\Name('name'))->counter(new Label('name', 'value'))->incr();

            return $int;
        };

        $promises = [];
        foreach (range(0, 3) as $i) {
            $promises[] = $limitedPool->run($fn, [$i, $registryProxy, 0]);
        }

        $leet = $this->await(
            // @phpstan-ignore-next-line
            all($promises)->then(static function (array $v) use ($factory): PromiseInterface {
                return new Promise(static function (callable $resolve) use ($v, $factory): void {
                    $factory->loop()->addTimer(3, static function () use ($resolve, $v): void {
                        $resolve($v);
                    });
                });
            })->then(static function () use ($fn, $registryProxy, $limitedPool): PromiseInterface {
                return $limitedPool->run($fn, [1337, $registryProxy, 20]);
            })->always(static function () use ($limitedPool, $loop): void {
                $limitedPool->kill();
                $loop->stop();
            }),
            $loop,
            133
        );
        self::assertSame(1337, $leet);
    }

    /**
     * @test
     * @dataProvider provideBooleans
     */
    public function sharedWillAlwaysReturnTheSameProxy(bool $share): void
    {
        $loop     = EventLoopFactory::create();
        $factory  = new Factory($loop);
        $registry = new InMemmoryRegistry(MetricsConfiguration::create()->withClock(FrozenClock::fromUTC()));
        $proxy    = new Proxy(new Configuration($factory));
        self::assertInstanceOf(
            Registry::class,
            $share ? $proxy->share($registry, Registry::class) : $proxy->create($registry, Registry::class)
        );
        self::assertInstanceOf(Registry::class, $proxy->create($registry, Registry::class));
    }

    /**
     * @return iterable<array<bool>>
     */
    public function provideBooleans(): iterable
    {
        yield 'true' => [TRUE_];
        yield 'false' => [FALSE_];
    }

    /**
     * @test
     */
    public function cannotCloseProxyTwice(): void
    {
        self::expectException(ClosedException::class);

        $loop    = EventLoopFactory::create();
        $factory = new Factory($loop);
        $proxy   = new Proxy(new Configuration($factory));
        $proxy->close();
        $proxy->close();
    }

    /**
     * @test
     */
    public function cannotUseClosedProxyHas(): void
    {
        self::expectException(ClosedException::class);

        $loop    = EventLoopFactory::create();
        $factory = new Factory($loop);
        $proxy   = new Proxy(new Configuration($factory));
        $proxy->close();
        $proxy->has('string');
    }

    /**
     * @test
     */
    public function cannotUseClosedProxyShare(): void
    {
        self::expectException(ClosedException::class);

        $loop    = EventLoopFactory::create();
        $factory = new Factory($loop);
        $proxy   = new Proxy(new Configuration($factory));
        $proxy->close();
        $proxy->share(new stdClass(), 'string');
    }

    /**
     * @test
     */
    public function cannotUseClosedProxyThread(): void
    {
        self::expectException(ClosedException::class);

        $loop    = EventLoopFactory::create();
        $factory = new Factory($loop);
        $proxy   = new Proxy(new Configuration($factory));
        $proxy->close();
        $proxy->thread(new stdClass(), 'string');
    }

    /**
     * @test
     */
    public function cannotUseClosedProxyCreate(): void
    {
        self::expectException(ClosedException::class);

        $loop    = EventLoopFactory::create();
        $factory = new Factory($loop);
        $proxy   = new Proxy(new Configuration($factory));
        $proxy->close();
        $proxy->create(new stdClass(), 'string');
    }
}
