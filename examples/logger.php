<?php

use Money\Money;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory;
use ReactParallel\Factory as ParallelFactory;
use ReactParallel\ObjectProxy\Configuration;
use ReactParallel\ObjectProxy\Proxy;
use ReactParallel\Pool\Worker\Workers\ReturnWorkerFactory;
use ReactParallel\Pool\Worker\Workers\Work;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor/autoload.php';

$loop = Factory::create();
$parallelFactory = new ParallelFactory($loop);
$proxy = new Proxy(new Configuration($parallelFactory));
$logger = new Logger('logger');
$logger->pushHandler(new StreamHandler(STDOUT));
$loggerProxy = $proxy->create($logger, LoggerInterface::class);
$parallelFactory->call(static function (LoggerInterface $logger, int $time): int {
    $logger->critical('Time: ' . $time);

    return $time;
}, [$loggerProxy, time()])->then(static function(int $time) use ($parallelFactory): void {
    echo 'Time: ', $time;
    $parallelFactory->lowLevelPool()->kill();
})->done();

$loop->run();
