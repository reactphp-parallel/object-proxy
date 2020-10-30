<?php

use React\EventLoop\Factory;
use ReactParallel\Factory as ParallelFactory;
use ReactParallel\ObjectProxy\Proxy;
use WyriHaximus\Metrics\InMemory\Registry as InMemoryRegistry;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Printer\Prometheus;
use WyriHaximus\Metrics\Registry;
use function React\Promise\all;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor/autoload.php';

$loop = Factory::create();
$parallelFactory = new ParallelFactory($loop);
$proxy = new Proxy($parallelFactory);
$registry = new InMemoryRegistry();
$registryProxy = $proxy->create($registry, Registry::class, true);
$fun = static function (Registry $registry): int {
    $registry->counter('name','description', new Label\Name('label'))->counter(new Label('label', 'label'))->incrBy(13);
    sleep(1);

    return time();
};
$promises = [];
foreach (range(0, 4) as $i) {
    $promises[] = $parallelFactory->call($fun, [$registryProxy]);
}
all($promises)->always(static function() use ($parallelFactory, $registry): void {
    echo $registry->print(new Prometheus());

    $parallelFactory->lowLevelPool()->kill();
})->done();

$loop->run();
