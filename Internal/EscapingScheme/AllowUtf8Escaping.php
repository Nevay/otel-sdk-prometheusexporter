<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\EscapingScheme;

use Nevay\OTelSDK\Prometheus\Internal\EscapingScheme;

/**
 * @internal
 */
final class AllowUtf8Escaping implements EscapingScheme {

    public function escapeName(string $name): string {
        return $name;
    }
}
