<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\ExpositionFormat;

use Nevay\OTelSDK\Metrics\Data\Exemplar;
use Nevay\OTelSDK\Prometheus\Internal\ExpositionFormat;
use Nevay\OTelSDK\Prometheus\Internal\PrometheusType;
use function number_format;

/**
 * @internal
 */
final class OpenMetricsFormat implements ExpositionFormat {

    public function selectExemplar(iterable $exemplars): ?Exemplar {
        /** @var Exemplar|null $candidate */
        $candidate = null;
        foreach ($exemplars as $exemplar) {
            if (!$candidate || $candidate->value < $exemplar->value) {
                $candidate = $exemplar;
            }
        }

        return $candidate;
    }

    public function formatTimestamp(int $timestamp): string {
        return number_format($timestamp / 1e6, 3, '.', '');
    }

    public function supportsTargetInfo(): bool {
        return true;
    }

    public function typeSuffix(PrometheusType $type): ?string {
        return null;
    }

    public function supportsCreated(): bool {
        return true;
    }
}
