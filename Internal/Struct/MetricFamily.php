<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\Struct;

use Nevay\OTelSDK\Metrics\Data\Metric;
use Nevay\OTelSDK\Prometheus\Internal\PrometheusType;

/**
 * @internal
 */
final class MetricFamily {

    /**
     * @param list<Metric> $metrics
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $unit,
        public readonly ?string $description,
        public readonly PrometheusType $type,
        public readonly ?string $typeSuffix,
        public array $metrics = [],
    ) {}
}
