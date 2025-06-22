<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\Struct;

/**
 * @internal
 */
final class MetricLabels {

    public function __construct(
        public readonly array $sanitizedLabels,
        public readonly array $labels,
        public readonly array $values,
    ) {}
}
