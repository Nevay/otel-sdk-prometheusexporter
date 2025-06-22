<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\ByteStream;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\WritableStream;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use function strlen;

/**
 * @internal
 */
final class InMemoryBuffer implements WritableStream {
    use ForbidCloning;
    use ForbidSerialization;

    private readonly DeferredFuture $deferredFuture;
    private readonly WritableStream $sink;
    private readonly int $bufferSize;

    private string $buffer = '';
    private bool $writable = true;
    private bool $closed = false;

    public function __construct(WritableStream $sink, int $bufferSize) {
        $this->deferredFuture = new DeferredFuture();
        $this->sink = $sink;
        $this->bufferSize = $bufferSize;
    }

    public function __destruct() {
        $this->close();
    }

    public function write(string $bytes): void {
        if ($this->closed) {
            throw new ClosedException('The stream has already been closed');
        }

        $this->buffer .= $bytes;
        if (strlen($this->buffer) >= $this->bufferSize) {
            $this->flush();
        }
    }

    public function end(): void {
        if (!$this->writable) {
            throw new ClosedException('The stream is not writable');
        }

        $this->writable = false;
        $this->flush();

        $this->sink->end();
    }

    public function isWritable(): bool {
        return $this->writable && $this->sink->isWritable();
    }

    public function close(): void {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->writable = false;
        $this->flush();

        $this->sink->close();
    }

    public function isClosed(): bool {
        return $this->closed ||  $this->sink->isClosed();
    }

    public function onClose(\Closure $onClose): void {
        $this->sink->onClose($onClose);
    }

    private function flush(): void {
        if (($buffer = $this->buffer) === '') {
            return;
        }

        $this->buffer = '';
        $this->sink->write($buffer);
    }
}
