<?php

use Money\Money;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory;
use ReactParallel\Factory as ParallelFactory;
use ReactParallel\ObjectProxy\Configuration;
use ReactParallel\ObjectProxy\Generated\Proxies\Psr\Log\LoggerInterface as LoggerInterfaceProxy;
use ReactParallel\ObjectProxy\Proxy;
use ReactParallel\Pool\Worker\Workers\ReturnWorkerFactory;
use ReactParallel\Pool\Worker\Workers\Work;
use function React\Promise\all;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor/autoload.php';

$loop = Factory::create();
$parallelFactory = new ParallelFactory($loop);
$proxy = new Proxy(new Configuration($parallelFactory));
$logger = new Logger('logger');
$logger->pushHandler(new StreamHandler('php://stdout'));
$loggerProxy = $proxy->thread($logger, LoggerInterface::class);

$promises = [];
foreach (range(1, 10) as $t) {
    $promises[] = $parallelFactory->call(function (LoggerInterfaceProxy $logger, int $time): int {
        $logger->critical('Time: ' . $time);
        foreach (range(0, 1337) as $i) {
            $logger->debug('Counting: ' . $i);
        }
        $logger->critical('Time: ' . $time);

        return $time;
    }, [$loggerProxy, time()]);
}

all($promises)->then(static function(array $times) use ($loop, $loggerProxy): void {
    sleep(30);
    $loggerProxy->emergency('Done!');
    sleep(30);

    echo 'Time: ', implode(', ', $times);
    $loop->stop();
})->done();

$loop->run();
