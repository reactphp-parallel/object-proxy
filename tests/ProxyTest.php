<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy;

use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory as EventLoopFactory;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\NonExistentInterface;
use ReactParallel\ObjectProxy\Proxy;
use stdClass;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use Yuloh\Container\Container;

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
            }, [$loggerProxy, $time])->always(static function () use ($loop): void {
                $loop->stop();
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

                return $time;
            }, [$containerProxy, $time])->always(static function () use ($loop): void {
                $loop->stop();
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
}
