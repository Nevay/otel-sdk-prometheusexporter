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
final class LengthStream implements WritableStream {
    use ForbidCloning;
    use ForbidSerialization;

    private readonly DeferredFuture $deferredFuture;

    private int $length = 0;
    private bool $closed = false;

    public function __construct() {
        $this->deferredFuture = new DeferredFuture();
    }

    public function write(string $bytes): void {
        if ($this->closed) {
            throw new ClosedException('The stream has already been closed');
        }

        $this->length += strlen($bytes);
    }

    public function end(): void {
        if ($this->closed) {
            throw new ClosedException('The stream has already been closed');
        }

        $this->close();
    }

    public function isWritable(): bool {
        return !$this->closed;
    }

    public function length(): int {
        if ($this->closed) {
            return $this->length;
        }

        return $this->deferredFuture->getFuture()->await();
    }

    public function close(): void {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $this->deferredFuture->complete($this->length);
    }

    public function isClosed(): bool {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void {
        $this->deferredFuture->getFuture()->finally($onClose);
    }
}
