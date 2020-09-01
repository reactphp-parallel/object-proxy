<?php

declare(strict_types=1);

namespace ReactParallel\ObjectProxy\Proxy;

use ReactParallel\ObjectProxy\Generated\ProxyList;
use ReactParallel\ObjectProxy\Message\Call;
use ReactParallel\ObjectProxy\Proxy;

use function array_key_exists;
use function get_class;
use function is_object;

final class CallHandler extends ProxyList
{
    private const HASNT_PROXYABLE_INTERFACE = false;

    /** @var array<string, string|false> */
    private array $detectedClasses = [];

    private Proxy $proxy;

    public function __construct(Proxy $proxy)
    {
        $this->proxy = $proxy;
    }

    public function __invoke(object $object, string $interface): callable
    {
        return function (Call $call) use ($object): void {
            /** @phpstan-ignore-next-line */
            $outcome = $object->{$call->method()}(...$call->args());
            if (is_object($outcome)) {
                $outcomeClass = get_class($outcome);
                if (array_key_exists($outcomeClass, $this->detectedClasses) === self::HASNT_PROXYABLE_INTERFACE) {
                    $this->detectedClasses[$outcomeClass] = $this->getInterfaceForOutcome($outcome) ?? self::HASNT_PROXYABLE_INTERFACE;
                }

                if ($this->detectedClasses[$outcomeClass] !== self::HASNT_PROXYABLE_INTERFACE) {
                    /** @psalm-suppress PossiblyFalseArgument */
                    $outcome = $this->proxy->create($outcome, $this->detectedClasses[$outcomeClass]);
                }
            }

            $call->channel()->send($outcome);
        };
    }

    /** @phpstan-ignore-next-line */
    private function getInterfaceForOutcome(object $outcome): ?string
    {
        foreach (self::KNOWN_INTERFACE as $interface => $proxy) {
            if ($outcome instanceof $interface) {
                return $interface;
            }
        }

        return null;
    }
}
