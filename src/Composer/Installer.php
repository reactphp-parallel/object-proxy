<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Illuminate\Support\Collection;
use PhpParser\Lexer\Emulative;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

use function array_unique;
use function array_values;
use function count;
use function defined;
use function dirname;
use function function_exists;
use function implode;
use function microtime;
use function round;
use function Safe\chmod;
use function Safe\file_get_contents;
use function Safe\file_put_contents;
use function Safe\sprintf;
use function str_replace;
use function var_export;
use function WyriHaximus\getIn;

use const PHP_INT_MIN;
use const WyriHaximus\Constants\Boolean\TRUE_;
use const WyriHaximus\Constants\Numeric\TWO;

final class Installer implements PluginInterface, EventSubscriberInterface
{
    /**
     * @return array<string, array<string|int>>
     */
    public static function getSubscribedEvents(): array
    {
        return [ScriptEvents::PRE_AUTOLOAD_DUMP => ['generateProxies', PHP_INT_MIN]];
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        // does nothing, see getSubscribedEvents() instead.
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // does nothing, see getSubscribedEvents() instead.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // does nothing, see getSubscribedEvents() instead.
    }

    /**
     * Called before every dump autoload, generates a fresh PHP class.
     */
    public static function generateProxies(Event $event): void
    {
        $start    = microtime(true);
        $io       = $event->getIO();
        $composer = $event->getComposer();

        // Composer is bugged and doesn't handle root package autoloading properly yet
        if (array_key_exists('psr-4', $composer->getPackage()->getAutoload())) {
            foreach ($composer->getPackage()->getAutoload()['psr-4'] as $ns => $p) {
                $p = dirname($composer->getConfig()->get('vendor-dir')) . '/' . $p;
                spl_autoload_register(static function ($class) use ($ns, $p) {
                    if (strpos($class, $ns) === 0) {
                        $fileName = $p . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($ns))) . '.php';
                        if (file_exists($fileName)) {
                            include $fileName;
                        }
                    }
                });
            }
        }

        $rootPath = self::locateRootPackageInstallPath($composer->getConfig(), $composer->getPackage());

        if (! function_exists('React\Promise\Resolve')) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $composer->getConfig()->get('vendor-dir') . '/react/promise/src/functions_include.php';
        }

        if (! function_exists('ApiClients\Tools\Rx\observableFromArray')) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $composer->getConfig()->get('vendor-dir') . '/api-clients/rx/src/functions_include.php';
        }

        if (! function_exists('WyriHaximus\iteratorOrArrayToArray')) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $composer->getConfig()->get('vendor-dir') . '/wyrihaximus/iterator-or-array-to-array/src/functions_include.php';
        }

        if (! function_exists('WyriHaximus\getIn')) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $composer->getConfig()->get('vendor-dir') . '/wyrihaximus/string-get-in/src/functions_include.php';
        }

        if (! defined('WyriHaximus\Constants\Numeric\ONE')) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $composer->getConfig()->get('vendor-dir') . '/wyrihaximus/constants/src/Numeric/constants_include.php';
        }

        if (! defined('WyriHaximus\Constants\Boolean\TRUE')) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $composer->getConfig()->get('vendor-dir') . '/wyrihaximus/constants/src/Boolean/constants_include.php';
        }

        if (! function_exists('igorw\get_in')) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $composer->getConfig()->get('vendor-dir') . '/igorw/get-in/src/get_in.php';
        }

        if (! function_exists('Safe\file_get_contents')) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $composer->getConfig()->get('vendor-dir') . '/thecodingmachine/safe/generated/filesystem.php';
        }

        if (! function_exists('Safe\sprintf')) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $composer->getConfig()->get('vendor-dir') . '/thecodingmachine/safe/generated/strings.php';
        }

        $io->write('<info>react-parallel/object-proxy:</info> Locating interfaces');

        $installPath = self::locateRootPackageInstallPath($composer->getConfig(), $composer->getPackage()) . '/src/Generated/';
        $proxies     = self::getProxies($composer, $io, $rootPath, $composer->getPackage());

        $io->write('<info>react-parallel/object-proxy:</info> Found ' . count($proxies) . ' interface(s) and generated a proxy for each of them');

        $interfaces = [];
        foreach ($proxies as $proxiers) {
            $interfaces[$proxiers->direct()->interfaceName()] = [
                'direct' => '\\' . implode('\\', InterfaceProxier::GENERATED_NAMESPACE) . '\\' . $proxiers->direct()->className(),
                'deferred' => '\\' . implode('\\', DeferredInterfaceProxier::GENERATED_NAMESPACE) . '\\' . $proxiers->deferred()->className(),
            ];

            file_put_contents($installPath . $proxiers->direct()->className() . '.php', "<?php\r\n" . (new Standard())->prettyPrint($proxiers->direct()->stmts()) . "\r\n");
            chmod($installPath . $proxiers->direct()->className() . '.php', 0664);
            file_put_contents($installPath . $proxiers->deferred()->className() . '.php', "<?php\r\n" . (new Standard())->prettyPrint($proxiers->deferred()->stmts()) . "\r\n");
            chmod($installPath . $proxiers->deferred()->className() . '.php', 0664);
        }

        $classContents = sprintf(
            str_replace(
                "['%s']",
                '%s',
                file_get_contents(
                    $rootPath . '/etc/ProxyList.php'
                )
            ),
            var_export($interfaces, TRUE_)
        );

        file_put_contents($installPath . 'ProxyList.php', $classContents);
        chmod($installPath . 'ProxyList.php', 0664);

        $io->write(sprintf(
            '<info>react-parallel/object-proxy:</info> Generated static abstract proxy and proxies in %s second(s)',
            round(microtime(TRUE_) - $start, TWO)
        ));
    }

    /**
     * Find the location where to put the generate PHP class in.
     */
    private static function locateRootPackageInstallPath(
        Config $composerConfig,
        RootPackageInterface $rootPackage
    ): string {
        // You're on your own
        if ($rootPackage->getName() === 'react-parallel/object-proxy') {
            return dirname($composerConfig->get('vendor-dir'));
        }

        return $composerConfig->get('vendor-dir') . '/react-parallel/object-proxy';
    }

    /**
     * @return array<Proxiers>
     */
    private static function getProxies(Composer $composer, IOInterface $io, string $rootPath, RootPackageInterface $rootPackage): array
    {
        $phpParser = $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, new Emulative(['comments' => true]));

        $packages   = $composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        $packages[] = $composer->getPackage();

        try {
            return (new Collection(array_unique(array_values((new Collection($packages))->filter(
                static fn (PackageInterface $package): bool => (bool) count($package->getAutoload())
            )->flatMap(
                static fn (PackageInterface $package): array => getIn($package->getExtra(), 'react-parallel.object-proxy.interfaces-to-proxy', [])
            )->all()))))->flatMap(
                /**
                 * @return array<Proxiers>
                 */
                static function (string $interface) use ($io, $phpParser, $rootPath, $rootPackage): array {
                    $io->write(sprintf('<info>react-parallel/object-proxy:</info> Creating proxy for %s', $interface));

                    /**
                     * @psalm-suppress ArgumentTypeCoercion
                     * @phpstan-ignore-next-line
                     */
                    $fileName = (new ReflectionClass($interface))->getFileName();
                    if ($interface === LoggerInterface::class) {
                        $fileName = ($rootPackage->getName() === 'react-parallel/object-proxy' ? $rootPath : dirname($rootPath, 3)) . '/vendor/psr/log/Psr/Log/LoggerInterface.php';
                    }

                    if ($fileName === false || $fileName === '') {
                        return [];
                    }

                    return [
                        new Proxiers(
                            new InterfaceProxier($phpParser->parse(file_get_contents($fileName)) ?? []),
                            new DeferredInterfaceProxier($phpParser->parse(file_get_contents($fileName)) ?? []),
                        ),
                    ];
                }
            )->all();
        } catch (Throwable $throwable) { /** @phpstan-ignore-line */
            $io->write(sprintf('<info>react-parallel/object-proxy:</info> Unexpected error: <fg=red>%s</>', $throwable->getMessage()));

            return [];
        }
    }
}
