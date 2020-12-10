<?php

use React\EventLoop\Factory;
use ReactParallel\Factory as ParallelFactory;
use ReactParallel\ObjectProxy\Configuration;
use ReactParallel\ObjectProxy\Generated\WyriHaximus__Metrics_RegistryProxy;
use ReactParallel\ObjectProxy\Proxy;
use WyriHaximus\Metrics\Configuration as MetricsConfiguration;
use WyriHaximus\Metrics\InMemory\Registry as InMemoryRegistry;
use WyriHaximus\Metrics\Label;
use WyriHaximus\Metrics\Printer\Prometheus;
use WyriHaximus\Metrics\Registry;
use function React\Promise\all;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor/autoload.php';

$loop = Factory::create();
$parallelFactory = new ParallelFactory($loop);
$pool = $parallelFactory->limitedPool(1);
$proxy = new Proxy(new Configuration($parallelFactory));
/** @var Registry $registryProxy */
$registryProxy = $proxy->threadIt(new InMemoryRegistry(MetricsConfiguration::create()), Registry::class);
$fun = static function (WyriHaximus__Metrics_RegistryProxy $registry): int {
    $registry->setDeferredCallHandler(new Proxy\DeferredCallHandler());
    for ($i = 0; $i < 10; $i++) {
        $registry->counter('name', 'description', new Label\Name('label'))->counter(new Label('label', 'label'))->incr();
    }
    $registry->print(new Prometheus());

    return time();
};
$promises = [];
foreach (range(0, 1337) as $i) {
    $promises[] = $pool->run($fun, [$registryProxy]);
}
all($promises)->always(static function() use ($parallelFactory, $registryProxy, $proxy): void {
    echo $registryProxy->print(new Prometheus());

    $proxy->__destruct();
    $parallelFactory->lowLevelPool()->kill();
    $parallelFactory->loop()->stop();
})->done();

$loop->run();
