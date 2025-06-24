<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus;

use Amp\ByteStream;
use Amp\ByteStream\ClosedException;
use Amp\ByteStream\Compression\CompressingWritableStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;
use Closure;
use InvalidArgumentException;
use Negotiation\Accept;
use Negotiation\AcceptEncoding;
use Negotiation\EncodingNegotiator;
use Negotiation\Exception\Exception;
use Negotiation\Negotiator;
use Nevay\OTelSDK\Metrics\Aggregation;
use Nevay\OTelSDK\Metrics\Aggregation\DefaultAggregation;
use Nevay\OTelSDK\Metrics\Data\Temporality;
use Nevay\OTelSDK\Metrics\InstrumentType;
use Nevay\OTelSDK\Metrics\MetricExporter;
use Nevay\OTelSDK\Metrics\MetricReader;
use Nevay\OTelSDK\Metrics\MetricReaderAware;
use Nevay\OTelSDK\Prometheus\Internal\EscapingScheme\AllowUtf8Escaping;
use Nevay\OTelSDK\Prometheus\Internal\EscapingScheme\DotsEscaping;
use Nevay\OTelSDK\Prometheus\Internal\EscapingScheme\UnderscoresEscaping;
use Nevay\OTelSDK\Prometheus\Internal\EscapingScheme\ValuesEscaping;
use Nevay\OTelSDK\Prometheus\Internal\ExpositionFormat\PrometheusFormat;
use Nevay\OTelSDK\Prometheus\Internal\PrometheusWriter;
use Nevay\OTelSDK\Prometheus\Internal\ExpositionFormat\OpenMetricsFormat;
use Nevay\OTelSDK\Prometheus\Internal\ByteStream\InMemoryBuffer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Traversable;
use function extension_loaded;
use function iterator_to_array;

/**
 * @see https://opentelemetry.io/docs/specs/otel/metrics/sdk_exporters/prometheus/
 */
final class PrometheusMetricExporter implements MetricExporter, MetricReaderAware, RequestHandler {

    private readonly MetricReader $metricReader;

    private array $metrics = [];
    private int $pending = 0;

    private bool $closed = false;

    public function __construct(
        private readonly ?HttpServer $server = null,
        private readonly bool $withoutUnits = false,
        private readonly bool $withoutTypeSuffix = false,
        private readonly bool $withoutScopeInfo = false,
        private readonly bool $withoutTargetInfo = false,
        private readonly ?Closure $withResourceConstantLabels = null,
        private readonly Aggregation $aggregation = new DefaultAggregation(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->server?->start($this, new DefaultErrorHandler());
    }

    public function setMetricReader(MetricReader $metricReader): void {
        $this->metricReader = $metricReader;
    }

    public function handleRequest(Request $request): Response {
        if ($this->closed) {
            return new Response(HttpStatus::SERVICE_UNAVAILABLE);
        }

        /** @var Accept $accept */
        /** @var AcceptEncoding|null $acceptEncoding */

        try {
            $accept = (new Negotiator())->getBest($request->getHeader('Accept'), [
                'text/plain;version=0.0.4;charset=utf-8',
                'text/plain;version=1.0.0;charset=utf-8;escaping=underscores',
                'text/plain;version=1.0.0;charset=utf-8;escaping=allow-utf-8',
                'text/plain;version=1.0.0;charset=utf-8;escaping=dots',
                'text/plain;version=1.0.0;charset=utf-8;escaping=values',
                'application/openmetrics-text;version=0.0.1;charset=utf-8',
                'application/openmetrics-text;version=1.0.0;charset=utf-8;escaping=underscores',
                'application/openmetrics-text;version=1.0.0;charset=utf-8;escaping=allow-utf-8',
                'application/openmetrics-text;version=1.0.0;charset=utf-8;escaping=dots',
                'application/openmetrics-text;version=1.0.0;charset=utf-8;escaping=values',
            ], true);
        } catch (InvalidArgumentException | Exception) {}
        $accept ??= new Accept('text/plain;version=0.0.4;charset=utf-8');

        try {
            $encodings = [];
            $encodings[] = 'identity';
            if (extension_loaded('zlib')) {
                $encodings[] = 'gzip';
                $encodings[] = 'deflate';
            }
            $acceptEncoding = (new EncodingNegotiator())->getBest($request->getHeader('Accept-Encoding'), $encodings, true);
        } catch (InvalidArgumentException | Exception) {}
        $acceptEncoding ??= null;

        $scrapeTimeout = +$request->getHeader('x-prometheus-scrape-timeout-seconds');
        $cancellation = $scrapeTimeout > 0
            ? new TimeoutCancellation(+$scrapeTimeout)
            : null;

        $this->pending++;
        try {
            $this->metricReader->collect($cancellation);
            $metrics = $this->metrics;
        } catch (CancelledException) {
            return new Response(HttpStatus::SERVICE_UNAVAILABLE);
        } finally {
            if (!--$this->pending) {
                $this->metrics = [];
            }
        }

        $writer = new PrometheusWriter(
            withoutUnits: $this->withoutUnits,
            withoutTypeSuffix: $this->withoutTypeSuffix,
            withoutScopeInfo: $this->withoutScopeInfo,
            withoutTargetInfo: $this->withoutTargetInfo,
            withoutJobInfo: true,
            withoutTimestamps: true,
            withResourceConstantLabels: $this->withResourceConstantLabels,
            escapingScheme: match ($accept->getParameter('escaping', 'underscores')) {
                'allow-utf-8' => new AllowUtf8Escaping(),
                'underscores' => new UnderscoresEscaping(),
                'dots' => new DotsEscaping(),
                'values' => new ValuesEscaping(),
            },
            format: match ($accept->getType()) {
                'text/plain' => new PrometheusFormat(),
                'application/openmetrics-text' => new OpenMetricsFormat(),
            },
            logger: $this->logger,
        );

        $pipe = new ByteStream\Pipe(4096);
        $sink = $pipe->getSink();
        /** @noinspection PhpComposerExtensionStubsInspection */
        $sink = new InMemoryBuffer(match ($acceptEncoding?->getType()) {
            'gzip' => new CompressingWritableStream($sink, \ZLIB_ENCODING_GZIP),
            'deflate' => new CompressingWritableStream($sink, \ZLIB_ENCODING_RAW),
            null => $sink,
        }, 1024);

        EventLoop::queue(static function(PrometheusWriter $writer, WritableStream $sink, array $metrics): void {
            try {
                $writer->write($sink, $metrics);
                $sink->end();
            } catch (ClosedException) {}
        }, $writer, $sink, $metrics);

        $response = new Response();
        $response->setHeader('Content-Type', $accept->getNormalizedValue());
        $response->setHeader('Content-Encoding', $acceptEncoding?->getNormalizedValue() ?? []);
        $response->setHeader('Vary', 'Accept, Accept-Encoding');
        $response->setBody($pipe->getSource());

        return $response;
    }

    public function export(iterable $batch, ?Cancellation $cancellation = null): Future {
        if ($batch instanceof Traversable) {
            $batch = iterator_to_array($batch, false);
        }

        if ($this->pending) {
            $this->metrics = $batch;
        }

        return Future::complete(true);
    }

    public function shutdown(?Cancellation $cancellation = null): bool {
        if ($this->closed) {
            return false;
        }

        $this->closed = true;
        $this->server?->stop();

        return true;
    }

    public function forceFlush(?Cancellation $cancellation = null): bool {
        return !$this->closed;
    }

    public function resolveTemporality(InstrumentType $instrumentType): Temporality {
        return Temporality::Cumulative;
    }

    public function resolveAggregation(InstrumentType $instrumentType): Aggregation {
        return $this->aggregation;
    }
}
