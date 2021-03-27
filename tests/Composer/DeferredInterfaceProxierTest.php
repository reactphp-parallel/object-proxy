<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Composer;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReactParallel\ObjectProxy\Composer\DeferredInterfaceProxier;
use WyriHaximus\TestUtilities\TestCase;

use function Safe\file_get_contents;

use const DIRECTORY_SEPARATOR;

final class DeferredInterfaceProxierTest extends TestCase
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
        $interfaceProxier = new DeferredInterfaceProxier($ast, false);
        $code             = (new Standard())->prettyPrint($interfaceProxier->stmts());

        self::assertStringContainsString('use stdClass as FakeClassNameButInternallSoYouCanIgnoreThis, React\Promise\PromiseInterface, Rx\Observable, ReactParallel\Tests\ObjectProxy\Composer\EdgeCaseInterface, ReactParallel\Tests\ObjectProxy\Composer\SameClassInTheSameNamespace;', $code);
        self::assertStringContainsString('* @return Observable<SameClassInTheSameNamespace>', $code);
        self::assertStringContainsString('public function inception(EdgeCaseInterface $edgeCase) : Observable', $code);
        self::assertStringContainsString('* @return PromiseInterface<SameClassInTheSameNamespace>', $code);
        self::assertStringContainsString('public function noPromises(EdgeCaseInterface $edgeCase) : PromiseInterface', $code);
    }

    /**
     * @test
     */
    public function interfaceInSameNamespaceNoPromises(): void
    {
        $phpParser = $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $ast       = $phpParser->parse(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'EdgeCaseInterface.php'));

        self::assertIsArray($ast);
        self::assertGreaterThan(0, $ast);
        $interfaceProxier = new DeferredInterfaceProxier($ast, true);
        $code             = (new Standard())->prettyPrint($interfaceProxier->stmts());

        self::assertStringContainsString('use stdClass as FakeClassNameButInternallSoYouCanIgnoreThis, React\Promise\PromiseInterface, Rx\Observable, ReactParallel\Tests\ObjectProxy\Composer\EdgeCaseInterface, ReactParallel\Tests\ObjectProxy\Composer\SameClassInTheSameNamespace;', $code);
        self::assertStringContainsString('* @return array<SameClassInTheSameNamespace>', $code);
        self::assertStringContainsString('public function inception(EdgeCaseInterface $edgeCase) : array', $code);
        self::assertStringContainsString('* @return SameClassInTheSameNamespace', $code);
        self::assertStringContainsString('public function noPromises(EdgeCaseInterface $edgeCase) : SameClassInTheSameNamespace', $code);
    }
}
