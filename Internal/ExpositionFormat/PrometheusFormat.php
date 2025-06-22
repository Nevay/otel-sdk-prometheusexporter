<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\ExpositionFormat;

use Nevay\OTelSDK\Metrics\Data\Exemplar;
use Nevay\OTelSDK\Prometheus\Internal\ExpositionFormat;
use Nevay\OTelSDK\Prometheus\Internal\PrometheusType;
use function number_format;

/**
 * @internal
 */
final class PrometheusFormat implements ExpositionFormat {

    public function selectExemplar(iterable $exemplars): ?Exemplar {
        return null;
    }

    public function formatTimestamp(int $timestamp): string {
        return number_format($timestamp / 1e6, 0, '.', '');
    }

    public function supportsTargetInfo(): bool {
        return false;
    }

    public function typeSuffix(PrometheusType $type): ?string {
        return match ($type) {
            PrometheusType::Counter => '_total',
            default => null,
        };
    }

    public function supportsCreated(): bool {
        return false;
    }
}
