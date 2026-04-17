<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Ipc;

use PhpOpcua\SessionManager\Exception\DaemonException;

/**
 * Unix-domain-socket transport. The default on Linux / macOS where the daemon
 * exposes a socket file under `/tmp/` (or a user-chosen path) with `0600`
 * permissions.
 *
 * On modern Windows 10+ the kernel supports Unix sockets too, but for broad
 * portability use {@see TcpLoopbackTransport} (or a future named-pipe transport)
 * when targeting Windows — the daemon side may also prefer TCP loopback there
 * because ReactPHP's support for Unix sockets on Windows is still partial.
 */
final class UnixSocketTransport extends AbstractStreamTransport
{
    /**
     * @param string $socketPath Path to the daemon's Unix socket file.
     * @param float $timeout Connect + read timeout in seconds.
     */
    public function __construct(
        private readonly string $socketPath,
        float $timeout = 30.0,
    ) {
        parent::__construct($timeout);
    }

    /**
     * {@inheritDoc}
     */
    protected function openStream()
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($stream === false) {
            throw new DaemonException(sprintf(
                'Cannot connect to Unix socket %s: [%d] %s',
                $this->socketPath,
                $errno,
                $errstr ?: 'unknown error',
            ));
        }

        return $stream;
    }

    /**
     * {@inheritDoc}
     */
    public function describeEndpoint(): string
    {
        return 'unix://' . $this->socketPath;
    }
}
