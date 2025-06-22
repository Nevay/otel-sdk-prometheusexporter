<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Prometheus\Internal\Socket;

use Amp\Socket\BindContext;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\ServerSocket;
use Amp\Socket\ServerSocketFactory;
use Amp\Socket\SocketAddress;

/**
 * @internal
 */
final class UnreferencedServerSocketFactory implements ServerSocketFactory {

    public function __construct(
        private readonly ResourceServerSocketFactory $serverSocketFactory = new ResourceServerSocketFactory(),
    ) {}

    public function listen(SocketAddress|string $address, ?BindContext $bindContext = null): ServerSocket {
        $socket = $this->serverSocketFactory->listen($address, $bindContext);
        $socket->unreference();

        return new UnreferencedServerSocket($socket);
    }
}
