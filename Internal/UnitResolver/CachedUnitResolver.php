<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\UnitResolver;

use Nevay\OTelSDK\Prometheus\Internal\UnitResolver;
use function array_key_exists;

/**
 * @internal
 */
final class CachedUnitResolver implements UnitResolver {

    private array $cache = [];

    public function __construct(
        private readonly UnitResolver $unitResolver,
    ) {}

    public function resolve(string $unit): ?string {
        if (array_key_exists($unit, $this->cache)) {
            return $this->cache[$unit];
        }

        return $this->cache[$unit] = $this->unitResolver->resolve($unit);
    }
}
