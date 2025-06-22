<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal;

/**
 * @internal
 */
interface UnitResolver {

    public function resolve(string $unit): ?string;
}
