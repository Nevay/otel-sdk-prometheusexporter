<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\Socket;

use Amp\Cancellation;
use Amp\Socket\BindContext;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;

/**
 * @internal
 */
final class UnreferencedServerSocket implements ServerSocket {

    public function __construct(
        private readonly ResourceServerSocket $serverSocket,
    ) {}

    public function accept(?Cancellation $cancellation = null): ?Socket {
        $socket = $this->serverSocket->accept($cancellation);
        $socket?->unreference();

        return $socket;
    }

    public function getAddress(): SocketAddress {
        return $this->serverSocket->getAddress();
    }

    public function getBindContext(): BindContext {
        return $this->serverSocket->getBindContext();
    }

    public function close(): void {
        $this->serverSocket->close();
    }

    public function isClosed(): bool {
        return $this->serverSocket->isClosed();
    }

    public function onClose(\Closure $onClose): void {
        $this->serverSocket->onClose($onClose);
    }
}
