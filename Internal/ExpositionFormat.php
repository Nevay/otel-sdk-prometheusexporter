<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal;

use Nevay\OTelSDK\Metrics\Data\Exemplar;

/**
 * @internal
 */
interface ExpositionFormat {

    /**
     * @param iterable<Exemplar> $exemplars
     */
    public function selectExemplar(iterable $exemplars): ?Exemplar;

    public function formatTimestamp(int $timestamp): string;

    public function supportsTargetInfo(): bool;

    public function typeSuffix(PrometheusType $type): ?string;

    public function supportsCreated(): bool;
}
