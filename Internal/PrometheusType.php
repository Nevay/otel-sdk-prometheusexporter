<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal;

/**
 * @internal
 */
enum PrometheusType: string {

    case Gauge = 'gauge';
    case Counter = 'counter';
    case Histogram = 'histogram';
}
