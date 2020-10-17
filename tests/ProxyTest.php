<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy;

use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory as EventLoopFactory;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Generated\WyriHaximus__Metrics_RegistryProxy;
use ReactParallel\ObjectProxy\NonExistentInterface;
use ReactParallel\ObjectProxy\Proxy;
use stdClass;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use WyriHaximus\Metrics\InMemory\Registry as InMemmoryRegistry;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Printer\Prometheus;
use WyriHaximus\Metrics\Registry;
use Yuloh\Container\Container;

use function count;
use function range;
use function React\Promise\all;
use function Safe\sleep;
use function time;

final class ProxyTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function logger(): void
    {
        $loop        = EventLoopFactory::create();
        $factory     = new Factory($loop);
        $proxy       = new Proxy($factory);
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
        $proxy      = new Proxy($factory);
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
    public function createUnknown(): void
    {
        self::expectException(NonExistentInterface::class);

        (new Proxy(new Factory(EventLoopFactory::create())))->create(new stdClass(), 'NoordHollandsKanaal');
    }

    /**
     * @test
     */
    public function metricsDestructionTesting(): void
    {
        $loop          = EventLoopFactory::create();
        $factory       = new Factory($loop);
        $registry      = new InMemmoryRegistry();
        $proxy         = (new Proxy($factory))->withMetrics($registry);
        $limitedPool   = $factory->limitedPool(13);
        $registryProxy = $proxy->create($registry, Registry::class);
        $fn            = static function (int $int, WyriHaximus__Metrics_RegistryProxy $registryProxy): int {
            $registryProxy->setDeferredCallHandler(new Proxy\DeferredCallHandler());
            $registryProxy->counter('counter', 'bla bla bla', new Label\Name('name'))->counter(new Label('name', 'value'))->incr();

            return $int;
        };

        $promises = [];
        foreach (range(0, 25) as $i) {
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
                    $limitedPool->close();
                }),
                $loop
            );
            self::assertCount(count($promises), $results);
        } catch (TimeoutException $timeoutException) {
            // @ignoreException
        }

        $txt = $registry->print(new Prometheus());
        self::assertStringContainsString('counter_total{name="value"} 26', $txt);
        self::assertStringContainsString('react_parallel_object_proxy_create_total{class="WyriHaximus\Metrics\InMemory\Registry",interface="WyriHaximus\Metrics\Registry"} 1', $txt);
        self::assertStringContainsString('react_parallel_object_proxy_create_total{class="WyriHaximus\Metrics\InMemory\Registry\Counters",interface="WyriHaximus\Metrics\Registry\Counters"} 26', $txt);
        self::assertStringContainsString('react_parallel_object_proxy_create_total{class="WyriHaximus\Metrics\InMemory\Counter",interface="WyriHaximus\Metrics\Counter"} 26', $txt);
        self::assertStringContainsString('react_parallel_object_proxy_call_total{class="WyriHaximus\Metrics\InMemory\Registry",interface="WyriHaximus\Metrics\Registry"} 26', $txt);
        self::assertStringContainsString('react_parallel_object_proxy_call_total{class="WyriHaximus\Metrics\InMemory\Registry\Counters",interface="WyriHaximus\Metrics\Registry\Counters"} 26', $txt);
        self::assertStringContainsString('react_parallel_object_proxy_notify_total{class="WyriHaximus\Metrics\InMemory\Counter",interface="WyriHaximus\Metrics\Counter"} 26', $txt);
//        self::assertStringContainsString('react_parallel_object_proxy_destruct_total{class="WyriHaximus\Metrics\InMemory\Registry\Counters",interface="WyriHaximus\Metrics\Registry\Counters"} 26', $txt);
//        self::assertStringContainsString('react_parallel_object_proxy_destruct_total{class="WyriHaximus\Metrics\InMemory\Counter",interface="WyriHaximus\Metrics\Counter"} 26', $txt);
//        self::assertStringContainsString('react_parallel_object_proxy_destruct_total{class="WyriHaximus\Metrics\InMemory\Registry",interface="WyriHaximus\Metrics\Registry"} 13', $txt);
    }

    /**
     * @test
     */
    public function destructionEdgeCases(): void
    {
        $loop          = EventLoopFactory::create();
        $factory       = new Factory($loop);
        $registry      = new InMemmoryRegistry();
        $proxy         = (new Proxy($factory))->withMetrics($registry);
        $limitedPool   = $factory->limitedPool(13);
        $registryProxy = $proxy->create($registry, Registry::class);
        $fn            = static function (int $int, WyriHaximus__Metrics_RegistryProxy $registryProxy, int $sleep): int {
            $registryProxy->notifyMainThreadAboutOurExistence();
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
}
