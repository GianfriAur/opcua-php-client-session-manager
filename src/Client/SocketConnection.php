<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Client;

use PhpOpcua\SessionManager\Exception\DaemonException;
use PhpOpcua\SessionManager\Ipc\TransportFactory;
use PhpOpcua\SessionManager\Ipc\TransportInterface;

/**
 * Low-level IPC transport for sending JSON-encoded commands to the daemon.
 *
 * Transport-agnostic: the `$endpoint` string is resolved through
 * {@see TransportFactory}, so the same helper works over Unix-domain sockets
 * (Linux/macOS default) and TCP loopback (Windows default, portable fallback).
 * A scheme-less string is treated as a Unix socket path for backwards
 * compatibility with the pre-v4.2.0 API.
 */
class SocketConnection
{
    /**
     * @param string $endpoint Endpoint URI (`unix://…`, `tcp://host:port`) or a Unix socket path.
     * @param array $payload The command payload to send.
     * @param float $timeout Timeout in seconds.
     * @return array The decoded JSON response from the daemon.
     *
     * @throws DaemonException If the transport fails or the response is invalid.
     */
    public static function send(string $endpoint, array $payload, float $timeout = 30.0): array
    {
        self::assertUnixSocketExists($endpoint);

        return self::sendVia(TransportFactory::create($endpoint, $timeout), $payload);
    }

    /**
     * Exchange one request / one response on an already-built transport.
     *
     * @param TransportInterface $transport
     * @param array $payload
     * @return array
     * @throws DaemonException
     */
    public static function sendVia(TransportInterface $transport, array $payload): array
    {
        try {
            $transport->connect();

            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $transport->sendLine($json);

            $response = $transport->receiveLine();
            if ($response === null || $response === '') {
                throw new DaemonException('Empty response from daemon');
            }

            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new DaemonException('Invalid response from daemon');
            }

            return $decoded;
        } finally {
            $transport->close();
        }
    }

    /**
     * Preserve the historical "socket file missing → friendlier error" hint
     * for Unix endpoints. TCP endpoints skip the pre-check.
     *
     * @param string $endpoint
     * @return void
     * @throws DaemonException
     */
    private static function assertUnixSocketExists(string $endpoint): void
    {
        $path = TransportFactory::toUnixPath($endpoint);
        if ($path !== null && ! file_exists($path)) {
            throw new DaemonException(sprintf('Socket not found: %s. Is the daemon running?', $path));
        }
    }
}
