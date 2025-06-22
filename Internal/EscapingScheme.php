<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal;

/**
 * @internal
 */
interface EscapingScheme {

    public function escapeName(string $name): string;
}
