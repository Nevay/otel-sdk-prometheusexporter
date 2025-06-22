<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal;

use Amp\ByteStream\WritableStream;
use Closure;
use Nevay\OTelSDK\Common\InstrumentationScope;
use Nevay\OTelSDK\Common\Resource;
use Nevay\OTelSDK\Metrics\Data\DataPoint;
use Nevay\OTelSDK\Metrics\Data\Exemplar;
use Nevay\OTelSDK\Metrics\Data\Gauge;
use Nevay\OTelSDK\Metrics\Data\Histogram;
use Nevay\OTelSDK\Metrics\Data\Metric;
use Nevay\OTelSDK\Metrics\Data\Sum;
use Nevay\OTelSDK\Metrics\Data\Temporality;
use Nevay\OTelSDK\Prometheus\Internal\ByteStream\LengthStream;
use Nevay\OTelSDK\Prometheus\Internal\EscapingScheme\UnderscoresEscaping;
use Nevay\OTelSDK\Prometheus\Internal\ExpositionFormat\PrometheusFormat;
use Nevay\OTelSDK\Prometheus\Internal\Struct\MetricFamily;
use Nevay\OTelSDK\Prometheus\Internal\Struct\MetricLabels;
use Nevay\OTelSDK\Prometheus\Internal\UnitResolver\CachedUnitResolver;
use Nevay\OTelSDK\Prometheus\Internal\UnitResolver\DefaultUnitResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Traversable;
use function array_is_list;
use function array_multisort;
use function assert;
use function base64_encode;
use function count;
use function gettype;
use function is_finite;
use function is_float;
use function is_string;
use function ksort;
use function mb_check_encoding;
use function preg_match;
use function spl_object_id;
use function str_ends_with;
use function strcmp;
use function strcspn;
use function strlen;
use function strtr;
use function substr;
use const INF;

/**
 * @internal
 */
final class PrometheusWriter {

    public function __construct(
        private readonly bool $withoutUnits = false,
        private readonly bool $withoutTypeSuffix = false,
        private readonly bool $withoutScopeInfo = false,
        private readonly bool $withoutTargetInfo = false,
        private readonly bool $withoutJobInfo = false,
        private readonly bool $withoutTimestamps = false,
        private readonly ?Closure $withResourceConstantLabels = null,
        private readonly EscapingScheme $escapingScheme = new UnderscoresEscaping(),
        private readonly ExpositionFormat $format = new PrometheusFormat(),
        private readonly UnitResolver $unitResolver = new DefaultUnitResolver(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param iterable<Metric> $batch
     */
    public function write(WritableStream $stream, iterable $batch): void {
        /** @var array<int, array<int, MetricLabels>> $resourceLabels */
        /** @var array<int, array<int, MetricLabels>> $constantLabels */
        /** @var array<string, MetricFamily> $metricFamilies */
        $resourceLabels = [];
        $constantLabels = [];
        $metricFamilies = [];
        $unitResolver = new CachedUnitResolver($this->unitResolver);
        foreach ($batch as $metric) {
            $type = match (true) {
                default => null,
                $metric->data instanceof Gauge,
                $metric->data instanceof Sum && $metric->data->temporality === Temporality::Cumulative && !$metric->data->monotonic,
                    => PrometheusType::Gauge,
                $metric->data instanceof Sum && $metric->data->temporality === Temporality::Cumulative && $metric->data->monotonic,
                    => PrometheusType::Counter,
                $metric->data instanceof Histogram && $metric->data->temporality === Temporality::Cumulative,
                    => PrometheusType::Histogram,
            };

            if ($type === null) {
                continue;
            }

            $typeSuffix = $this->format->typeSuffix($type);

            $name = $this->escapingScheme->escapeName($metric->descriptor->name);
            if ($typeSuffix !== null && str_ends_with($name, $typeSuffix)) {
                $name = substr($name, 0, -strlen($typeSuffix));
            }

            $unit = !$this->withoutUnits && $metric->descriptor->unit !== null
                ? $unitResolver->resolve($metric->descriptor->unit)
                : null;
            if ($unit !== null && (!str_ends_with($name, $unit) || ($name[~strlen($unit)] ?? '') !== '_')) {
                $name .= '_';
                $name .= $unit;
            }
            if ($typeSuffix !== null && !$this->withoutTypeSuffix) {
                $name .= $typeSuffix;
            }

            $metricFamily = $metricFamilies[$name] ??= new MetricFamily($name, $unit, $metric->descriptor->description, $type, $typeSuffix);

            if ($metricFamily->unit !== $unit || $metricFamily->type !== $type) {
                $this->logger->error('Dropping conflicting prometheus metric {name}', ['name' => $name, 'units' => [$metricFamily->unit, $unit], 'types' => [$metricFamily->type, $type]]);
                continue;
            }
            if ($metricFamily->description !== $metric->descriptor->description) {
                $this->logger->warning('Ignoring conflicting description of prometheus metric {name}', ['name' => $name, 'description' => [$metricFamily->type, $type]]);
            }

            $metricFamily->metrics[] = $metric;

            $resourceId = spl_object_id($metric->descriptor->resource);
            $scopeId = spl_object_id($metric->descriptor->instrumentationScope);

            if (!$this->withoutTargetInfo && !isset($resourceLabels[$resourceId]) && $this->format->supportsTargetInfo()) {
                $this->writeTargetInfo($stream, $metric->descriptor->resource);
            }

            $resourceLabels[$resourceId] ??= $this->computeConstantLabels($this->constantResourceLabels($metric->descriptor->resource));
            $constantLabels[$resourceId][$scopeId] ??= $this->computeConstantLabels($this->constantScopeLabel($metric->descriptor->instrumentationScope), $resourceLabels[$resourceId]);
        }
        unset($batch, $resourceLabels, $unitResolver);
        ksort($metricFamilies);

        foreach ($metricFamilies as $metricFamily) {
            $this->writeHeader($stream, $metricFamily->name, $metricFamily->unit, $metricFamily->description, $metricFamily->type);

            foreach ($metricFamily->metrics as $metric) {
                $resourceId = spl_object_id($metric->descriptor->resource);
                $scopeId = spl_object_id($metric->descriptor->instrumentationScope);
                $labels = $constantLabels[$resourceId][$scopeId];

                match ($metricFamily->type) {
                    PrometheusType::Gauge => $this->writePrometheusGauge($stream, $metricFamily->name, $metricFamily->typeSuffix, $metric, $labels),
                    PrometheusType::Counter => $this->writePrometheusCounter($stream, $metricFamily->name, $metricFamily->typeSuffix, $metric, $labels),
                    PrometheusType::Histogram => $this->writePrometheusHistogram($stream, $metricFamily->name, $metricFamily->typeSuffix, $metric, $labels),
                };
            }
        }

        $stream->write("# EOF\n");
    }

    private function writeTargetInfo(WritableStream $stream, Resource $resource): void {
        $stream->write("# TYPE target info\n# HELP target Target metadata\ntarget_info{");
        $this->writeLabels($stream, $resource->attributes, $this->jobAttributes($resource));
        $stream->write("} 1\n");
    }

    /**
     * @param Metric<Gauge|Sum> $metric
     */
    private function writePrometheusGauge(WritableStream $stream, string $name, ?string $typeSuffix, Metric $metric, MetricLabels $constantLabels): void {
        foreach ($metric->data->dataPoints as $dataPoint) {
            $this->writeValue($stream, $name, '', $typeSuffix, $dataPoint->value, $dataPoint->timestamp, $dataPoint, [], $constantLabels);
        }
    }

    /**
     * @param Metric<Sum> $metric
     */
    private function writePrometheusCounter(WritableStream $stream, string $name, ?string $typeSuffix, Metric $metric, MetricLabels $constantLabels): void {
        foreach ($metric->data->dataPoints as $dataPoint) {
            $this->writeValue($stream, $name, '_total', $typeSuffix, $dataPoint->value, $dataPoint->timestamp, $dataPoint, [], $constantLabels, $this->format->selectExemplar($dataPoint->exemplars));
            if ($this->format->supportsCreated()) {
                $this->writeValue($stream, $name, '_created', $typeSuffix, $dataPoint->startTimestamp / 1e6, $dataPoint->timestamp, $dataPoint, [], $constantLabels);
            }
        }
    }

    /**
     * @param Metric<Histogram> $metric
     */
    private function writePrometheusHistogram(WritableStream $stream, string $name, ?string $typeSuffix, Metric $metric, MetricLabels $constantLabels): void {
        foreach ($metric->data->dataPoints as $dataPoint) {
            $runningCount = 0;
            $exemplar = null;
            for ($i = 0; $i < count($dataPoint->bucketCounts); $i++) {
                $runningCount += $dataPoint->bucketCounts[$i];
                $exemplar = $this->format->selectExemplar($dataPoint->exemplars) ?? $exemplar;
                $upperBound = $dataPoint->explicitBounds[$i] ?? +INF;
                $this->writeValue($stream, $name, '_bucket', $typeSuffix, $runningCount, $dataPoint->timestamp, $dataPoint, ['le' => $upperBound], $constantLabels, $exemplar);
            }
            if ($dataPoint->sum !== null) {
                $this->writeValue($stream, $name, '_sum', $typeSuffix, $dataPoint->sum, $dataPoint->timestamp, $dataPoint, [], $constantLabels);
            }
            assert($runningCount === $dataPoint->count);
            $this->writeValue($stream, $name, '_count', $typeSuffix, $dataPoint->count, $dataPoint->timestamp, $dataPoint, [], $constantLabels);
            if ($this->format->supportsCreated()) {
                $this->writeValue($stream, $name, '_created', $typeSuffix, $dataPoint->startTimestamp / 1e6, $dataPoint->timestamp, $dataPoint, [], $constantLabels);
            }
        }
    }

    private function writeHeader(WritableStream $stream, string $name, ?string $unit, ?string $description, PrometheusType $type): void {
        if (self::isLegacyMetricName($name)) {
            $stream->write('# TYPE ');
            $this->writeString($stream, $name);
            $stream->write(' ');
            $this->writeString($stream, $type->value);
            $stream->write("\n");
            if (!$this->withoutUnits && $unit !== null) {
                $stream->write('# UNIT ');
                $this->writeString($stream, $name);
                $stream->write(' ');
                $this->writeString($stream, $unit);
                $stream->write("\n");
            }
            if ($description !== null) {
                $stream->write('# HELP ');
                $this->writeString($stream, $name);
                $stream->write(' ');
                $this->writeString($stream, $description);
                $stream->write("\n");
            }
        } else {
            $stream->write('# TYPE "');
            $this->writeQuoted($stream, $name);
            $stream->write('" ');
            $this->writeString($stream, $type->value);
            $stream->write("\n");
            if (!$this->withoutUnits && $unit !== null) {
                $stream->write('# UNIT "');
                $this->writeQuoted($stream, $name);
                $stream->write('" ');
                $this->writeString($stream, $unit);
                $stream->write("\n");
            }
            if ($description !== null) {
                $stream->write('# HELP "');
                $this->writeQuoted($stream, $name);
                $stream->write('" ');
                $this->writeString($stream, $description);
                $stream->write("\n");
            }
        }
    }

    private function writeValue(WritableStream $stream, string $name, string $suffix, ?string $typeSuffix, float|int $value, int $timestamp, DataPoint $dataPoint, array $additionalAttributes, MetricLabels $constantLabels, ?Exemplar $exemplar = null): void {
        if (self::isLegacyMetricName($name)) {
            $this->writeString($stream, $name);
            if ($suffix !== $typeSuffix) {
                $this->writeString($stream, $suffix);
            }
            if ($dataPoint->attributes->count() || $additionalAttributes || $constantLabels->values) {
                $stream->write('{');
                $this->writeLabels($stream, $dataPoint->attributes, $additionalAttributes, $constantLabels);
                $stream->write('}');
            }
        } else {
            $stream->write('{"');
            $this->writeQuoted($stream, $name);
            if ($suffix !== $typeSuffix) {
                $this->writeQuoted($stream, $suffix);
            }
            $stream->write('"');
            if ($dataPoint->attributes->count() || $additionalAttributes || $constantLabels->values) {
                $stream->write(',');
                $this->writeLabels($stream, $dataPoint->attributes, $additionalAttributes, $constantLabels);
            }
            $stream->write('}');
        }
        $stream->write(' ');
        $this->writeNumber($stream, $value);
        if (!$this->withoutTimestamps) {
            $stream->write(' ');
            $this->writeString($stream, $this->format->formatTimestamp($timestamp));
        }
        if ($exemplar) {
            $labels = [];
            if ($exemplar->spanContext) {
                $labels['trace_id'] = $exemplar->spanContext->getTraceId();
                $labels['span_id'] = $exemplar->spanContext->getSpanId();
            }
            $buffer = new LengthStream();
            $this->writeLabels($buffer, $labels, $exemplar->attributes);
            $buffer->end();

            $stream->write(' # {');
            $buffer->length() <= 128
                ? $this->writeLabels($stream, $labels, $exemplar->attributes)
                : $this->writeLabels($stream, $labels);
            $stream->write('} ');
            $this->writeNumber($stream, $exemplar->value);
            $stream->write(' ');
            $this->writeString($stream, $this->format->formatTimestamp($exemplar->timestamp));
        }
        $stream->write("\n");
    }

    private function computeConstantLabels(iterable $attributes, MetricLabels $metricLabels = new MetricLabels([], [], [])): MetricLabels {
        $sanitizedLabels = $metricLabels->sanitizedLabels;
        $labels = $metricLabels->labels;
        $values = $metricLabels->values;
        foreach ($attributes as $label => $value) {
            $sanitizedLabels[] = $this->escapingScheme->escapeName($label);
            $labels[] = $label;
            $values[] = $value;
        }
        array_multisort($sanitizedLabels, $labels, $values);

        return new MetricLabels($sanitizedLabels, $labels, $values);
    }

    private function writeLabels(WritableStream $stream, iterable $attributes, iterable $additionalAttributes = [], MetricLabels $constantLabels = new MetricLabels([], [], [])): void {
        $sanitizedLabels = $constantLabels->sanitizedLabels;
        $labels = $constantLabels->labels;
        $values = $constantLabels->values;
        foreach ($attributes as $label => $value) {
            $sanitizedLabels[] = $this->escapingScheme->escapeName($label);
            $labels[] = $label;
            $values[] = $value;
        }
        foreach ($additionalAttributes as $label => $value) {
            $sanitizedLabels[] = $this->escapingScheme->escapeName($label);
            $labels[] = $label;
            $values[] = $value;
        }
        array_multisort($sanitizedLabels, $labels, $values);

        $prev = '';
        foreach ($sanitizedLabels as $index => $label) {
            $cmp = strcmp($label, $prev);
            if (!$cmp) {
                // Identical name, merge using ';'
                $stream->write(';');
            } else {
                assert($cmp > 0);
                if ($prev !== '') {
                    $stream->write('",');
                }
                if (self::isLegacyLabelName($label)) {
                    $this->writeString($stream, $label);
                } else {
                    $stream->write('"');
                    $this->writeQuoted($stream, $label);
                    $stream->write('"');
                }
                $stream->write('="');
            }
            $this->writeLabelValue($stream, $values[$index]);
            $prev = $label;
        }
        if ($prev !== '') {
            $stream->write('"');
        }
    }

    private static function writeLabelValue(WritableStream $stream, mixed $value): void {
        match (gettype($value)) {
            'double', 'integer' => self::writeNumber($stream, $value),
            'boolean' => $stream->write($value ? 'true' : 'false'),
            'string' => self::writeQuoted($stream, self::isUtf8($value) ? $value : 'data;base64,' . base64_encode($value)),
            'array' => self::writeLabelArray($stream, $value),
            'NULL' => $stream->write('null'),
            default => null,
        };
    }

    private static function writeLabelArray(WritableStream $stream, array $values): void {
        if (array_is_list($values)) {
            self::writeLabelList($stream, $values);
        } else {
            self::writeLabelMap($stream, $values);
        }
    }

    private static function writeLabelMap(WritableStream $stream, array $values): void {
        $stream->write('{');
        $first = true;
        foreach ($values as $key => $value) {
            if ($first) {
                $first = false;
            } else {
                $stream->write(',');
            }

            $stream->write('\"');
            self::writeLabelValue($stream, $key);
            $stream->write('\"');
            if (is_string($value)) {
                $stream->write('\"');
                self::writeLabelValue($stream, $value);
                $stream->write('\"');
            } else {
                self::writeLabelValue($stream, $value);
            }
        }
        $stream->write('}');
    }

    private static function writeLabelList(WritableStream $stream, array $values): void {
        $stream->write('[');
        $first = true;
        foreach ($values as $value) {
            if ($first) {
                $first = false;
            } else {
                $stream->write(',');
            }

            if (is_string($value)) {
                $stream->write('\"');
            }
            self::writeLabelValue($stream, $value);
            if (is_string($value)) {
                $stream->write('\"');
            }
        }
        $stream->write(']');
    }

    private static function writeNumber(WritableStream $stream, float|int $value): void {
        if (!is_finite($value)) {
            $stream->write(match ($value) {
                +INF => '+Inf',
                -INF => '-Inf',
                default => 'NaN',
            });
            return;
        }

        $v = (string) $value;
        $stream->write($v);
        if (is_float($value) && strcspn($v, '.e') === strlen($v)) {
            $stream->write('.0');
        }
    }

    private static function writeString(WritableStream $stream, string $value): void {
        $stream->write(strtr($value, ['\\' => '\\\\', "\n" => '\n']));
    }

    private static function writeQuoted(WritableStream $stream, string $value): void {
        $stream->write(strtr($value, ['\\' => '\\\\', "\n" => '\n', '"' => '\"']));
    }

    private function jobAttributes(Resource $resource): Traversable {
        if ($this->withoutJobInfo) {
            return;
        }

        $serviceName = $resource->attributes->get('service.name') ?? Resource::default()->attributes->get('service.name');
        $serviceNamespace = $resource->attributes->get('service.namespace');

        yield 'job' => $serviceNamespace !== null
            ? $serviceNamespace . '/' . $serviceName
            : $serviceName;
        yield 'instance' => $resource->attributes->get('service.instance.id') ?? '';
    }

    private function constantResourceLabels(Resource $resource): iterable {
        if ($this->withResourceConstantLabels) {
            foreach ($resource->attributes as $key => $value) {
                if (($this->withResourceConstantLabels)($key)) {
                    yield $key => $value;
                }
            }
        }
        yield from $this->jobAttributes($resource);
    }

    private function constantScopeLabel(InstrumentationScope $scope): iterable {
        if ($this->withoutScopeInfo) {
            return;
        }

        yield 'otel_scope_name' => $scope->name;
        if ($scope->version !== null) {
            yield 'otel_scope_version' => $scope->version;
        }
        if ($scope->schemaUrl !== null) {
            yield 'otel_scope_schema_url' => $scope->schemaUrl;
        }
        foreach ($scope->attributes as $key => $attribute) {
            yield 'otel_scope_' . $key => $attribute;
        }
    }

    private static function isLegacyMetricName(string $name): bool {
        return (bool) preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*+$/', $name);
    }

    private static function isLegacyLabelName(string $name): bool {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*+$/', $name);
    }

    private static function isUtf8(string $value): bool {
        return mb_check_encoding($value, 'UTF-8');
    }
}
