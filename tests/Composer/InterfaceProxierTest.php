<?php

declare(strict_types=1);

namespace ReactParallel\Tests\ObjectProxy\Composer;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReactParallel\ObjectProxy\Composer\InterfaceProxier;
use WyriHaximus\TestUtilities\TestCase;

use function Safe\file_get_contents;

use const DIRECTORY_SEPARATOR;

final class InterfaceProxierTest extends TestCase
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
        $interfaceProxier = new InterfaceProxier($ast, true);
        $code             = (new Standard())->prettyPrint($interfaceProxier->stmts());

        self::assertStringContainsString('public function inception(\ReactParallel\Tests\ObjectProxy\Composer\EdgeCaseInterface $edgeCase)', $code);
    }
}
