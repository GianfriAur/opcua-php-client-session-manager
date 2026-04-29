<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Ipc;

use PhpOpcua\SessionManager\Exception\DaemonException;

/**
 * TCP-loopback transport bound to `127.0.0.1` (IPv4) or `[::1]` (IPv6). Used on
 * Windows when the daemon cannot (or should not) expose a Unix-domain socket,
 * and as a portable fallback anywhere the default UDS path is unavailable.
 *
 * ### Security
 *
 * - The transport MUST be bound to a loopback address — never a routable
 *   interface — so that the socket is reachable only from processes on the same
 *   host. Binding to `0.0.0.0` would expose the daemon to the network; callers
 *   that require remote access should add an explicit transport layer (TLS,
 *   SSH tunnel, …) instead of widening the bind address here.
 * - Authentication still flows through the IPC-level `authToken` (shared
 *   secret) supplied on every request, identical to the Unix-socket path.
 *   Local-only binding + authToken is the same posture as the UDS default.
 */
final class TcpLoopbackTransport extends AbstractStreamTransport
{
    /**
     * @param string $host Loopback host — `127.0.0.1` or `::1`. Non-loopback values throw.
     * @param int $port Port on the loopback interface where the daemon listens.
     * @param float $timeout Connect + read timeout in seconds.
     * @throws DaemonException If `$host` is not a loopback address.
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 9990,
        float $timeout = 30.0,
    ) {
        if (! self::isLoopbackAddress($host)) {
            throw new DaemonException(sprintf(
                'TcpLoopbackTransport refuses to bind to non-loopback host "%s". '
                . 'Use 127.0.0.1 or ::1; add a TLS/tunnel layer explicitly if remote access is needed.',
                $host,
            ));
        }

        parent::__construct($timeout);
    }

    /**
     * {@inheritDoc}
     */
    protected function openStream()
    {
        $uri = str_contains($this->host, ':')
            ? sprintf('tcp://[%s]:%d', $this->host, $this->port)
            : sprintf('tcp://%s:%d', $this->host, $this->port);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $uri,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($stream === false) {
            throw new DaemonException(sprintf(
                'Cannot connect to TCP loopback %s: [%d] %s',
                $uri,
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
        return str_contains($this->host, ':')
            ? sprintf('tcp://[%s]:%d', $this->host, $this->port)
            : sprintf('tcp://%s:%d', $this->host, $this->port);
    }

    /**
     * @param string $host
     * @return bool
     */
    private static function isLoopbackAddress(string $host): bool
    {
        if ($host === '127.0.0.1' || $host === '::1' || $host === 'localhost') {
            return true;
        }
        if (str_starts_with($host, '127.') && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        $normalized = strtolower($host);
        if (str_starts_with($normalized, '::ffff:')) {
            $mapped = substr($normalized, 7);
            if ($mapped === '127.0.0.1' || str_starts_with($mapped, '127.')) {
                return filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            }
        }

        return false;
    }
}
