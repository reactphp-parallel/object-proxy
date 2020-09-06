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
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionClass;
use Rx\Observable;
use Throwable;

use function ApiClients\Tools\Rx\observableFromArray;
use function array_unique;
use function array_values;
use function count;
use function defined;
use function dirname;
use function function_exists;
use function implode;
use function is_array;
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
        $proxies     = self::getProxies($composer, $io);

        $io->write('<info>react-parallel/object-proxy:</info> Found ' . count($proxies) . ' interface(s) and generated a proxy for each of them');

        $interfaces = [];
        foreach ($proxies as $proxy) {
            $interfaces[$proxy->interfaceName()] = '\\' . implode('\\', InterfaceProxier::GENERATED_NAMESPACE) . '\\' . $proxy->className();

            file_put_contents($installPath . $proxy->className() . '.php', "<?php\r\n" . (new Standard())->prettyPrint($proxy->stmts()) . "\r\n");
            chmod($installPath . $proxy->className() . '.php', 0664);
        }

        $classContents = sprintf(
            str_replace(
                "['%s']",
                '%s',
                file_get_contents(
                    self::locateRootPackageInstallPath($composer->getConfig(), $composer->getPackage()) . '/etc/ProxyList.php'
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
     * @return array<InterfaceProxier>
     */
    private static function getProxies(Composer $composer, IOInterface $io): array
    {
        $phpParser = $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);

        $result     = [];
        $packages   = $composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        $packages[] = $composer->getPackage();
        observableFromArray($packages)->filter(
            static fn (PackageInterface $package): bool => (bool) count($package->getAutoload())
        )->flatMap(
            static fn (PackageInterface $package): Observable => observableFromArray(getIn($package->getExtra(), 'react-parallel.object-proxy.interfaces-to-proxy', []))
        )->toArray()->flatMap(
            static fn (array $interfaces): Observable => observableFromArray(array_unique(array_values($interfaces)))
        )->map(
            /** @phpstan-ignore-next-line */
            static function (string $interface) use ($io, $phpParser): ?array {
                $io->write(sprintf('<info>react-parallel/object-proxy:</info> Creating proxy for %s', $interface));

                /**
                 * @psalm-suppress ArgumentTypeCoercion
                 * @phpstan-ignore-next-line
                 */
                return $phpParser->parse(file_get_contents((new ReflectionClass($interface))->getFileName()));
            }
        )->filter(
            static fn (?array $ast): bool => is_array($ast)
        )->map(
            static fn (array $ast): InterfaceProxier => new InterfaceProxier($ast)
        )->toArray()->toPromise()->then(static function (array $interfaces) use (&$result): void {
            $result = $interfaces;
        }, static function (Throwable $throwable) use ($io): void {
            $io->write(sprintf('<info>react-parallel/object-proxy:</info> Unexpected error: <fg=red>%s</>', $throwable->getMessage()));
        });

        return $result;
    }
}
