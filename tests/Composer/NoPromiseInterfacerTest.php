<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Composer;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReactParallel\ObjectProxy\Composer\NoPromisesInterfacer;
use WyriHaximus\TestUtilities\TestCase;

use function Safe\file_get_contents;

use const DIRECTORY_SEPARATOR;

final class NoPromiseInterfacerTest extends TestCase
{
    /**
     * @test
     */
    public function interfaceInSameNamespace(): void
    {
        $phpParser = $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $ast       = $phpParser->parse(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'EdgeCaseInterface.php'));

        self::assertIsArray($ast);
        self::assertGreaterThan(0, $ast);
        $interfaceProxier = new NoPromisesInterfacer($ast);
        $code             = (new Standard())->prettyPrint($interfaceProxier->stmts());

        self::assertStringContainsString('use stdClass as FakeClassNameButInternallSoYouCanIgnoreThis, React\Promise\PromiseInterface, Rx\Observable, \ReactParallel\Tests\ObjectProxy\Composer\EdgeCaseInterface, ReactParallel\Tests\ObjectProxy\Composer\SameClassInTheSameNamespace;', $code);
        self::assertStringContainsString('* @return array<SameClassInTheSameNamespace>', $code);
        self::assertStringContainsString('public function inception(EdgeCaseInterface $edgeCase) : array', $code);
        self::assertStringContainsString('* @return SameClassInTheSameNamespace', $code);
        self::assertStringContainsString('public function noPromises(EdgeCaseInterface $edgeCase) : SameClassInTheSameNamespace', $code);
    }
}
