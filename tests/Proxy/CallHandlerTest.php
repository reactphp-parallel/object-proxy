<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Proxy;

use Monolog\Logger;
use parallel\Channel;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory as EventLoopFactory;
use ReactParallel\Factory;
use ReactParallel\ObjectProxy\Generated\Psr__Log_LoggerInterfaceProxy;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Proxy;
use ReactParallel\Tests\ObjectProxy\LoggerStub;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use Yuloh\Container\Container;

use function time;

final class CallHandlerTest extends AsyncTestCase
{
    /**
     * @test
     */
    public function logger(): void
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
        $callHandler = new Proxy\CallHandler($proxy);
        $time        = time();
        $channel     = new Channel(1);
        $call        = new Call($channel, 'get', [LoggerInterface::class]);

        $loop->futureTick(static function () use ($container, $callHandler, $call): void {
            ($callHandler)($container, ContainerInterface::class)($call);
        });

        $returnedTime = $this->await(
            /** @phpstan-ignore-next-line */
            $factory->streams()->single($channel)->always(static function () use ($loop): void {
                $loop->stop();
            }),
            $loop
        );

        self::assertInstanceOf(LoggerInterface::class, $returnedTime);
        self::assertInstanceOf(Psr__Log_LoggerInterfaceProxy::class, $returnedTime);
    }
}
