<?php

use Money\Money;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory;
use ReactParallel\Factory as ParallelFactory;
use ReactParallel\ObjectProxy\Configuration;
use ReactParallel\ObjectProxy\Proxy;
use ReactParallel\Pool\Worker\Workers\ReturnWorkerFactory;
use ReactParallel\Pool\Worker\Workers\Work;
use Yuloh\Container\Container;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor/autoload.php';

$loop = Factory::create();
$parallelFactory = new ParallelFactory($loop);
$proxy = new Proxy(new Configuration($parallelFactory));
$container = new Container();
$container->set(LoggerInterface::class, static function () {
    $logger = new Logger('logger');
    $logger->pushHandler(new StreamHandler(STDOUT));

    return $logger;
});
$containerProxy = $proxy->create($container, ContainerInterface::class);
$parallelFactory->call(static function (ContainerInterface $container, int $time): int {
    $logger = $container->get(LoggerInterface::class);
    for ($i = 0; $i < 1024; $i++) {
        $logger->critical('Time: ' . $time);
    }

    return $time;
}, [$containerProxy, time()])->then(static function(int $time) use ($parallelFactory): void {
    echo 'Time: ', $time;
    $parallelFactory->lowLevelPool()->kill();
})->done();

$loop->run();
