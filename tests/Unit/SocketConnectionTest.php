<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Client\SocketConnection;
use PhpOpcua\SessionManager\Exception\DaemonException;

function createFakeServer(bool $respond, string $response = '', int $delayMs = 0): array
{
    $socketPath = sys_get_temp_dir() . '/opcua_test_' . bin2hex(random_bytes(4)) . '.sock';

    if (file_exists($socketPath)) {
        unlink($socketPath);
    }

    $server = stream_socket_server("unix://{$socketPath}", $errorCode, $errorMessage);
    if ($server === false) {
        throw new RuntimeException("Cannot create test server: [{$errorCode}] {$errorMessage}");
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Cannot fork');
    }

    if ($pid === 0) {
        $conn = stream_socket_accept($server, 5);
        if ($conn !== false) {
            $data = '';
            while (!str_contains($data, "\n")) {
                $chunk = fread($conn, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            if ($respond && $response !== '') {
                fwrite($conn, $response . "\n");
            }

            fclose($conn);
        }
        fclose($server);
        if (file_exists($socketPath)) {
            unlink($socketPath);
        }
        exit(0);
    }

    fclose($server);
    usleep(50_000);

    return ['socketPath' => $socketPath, 'pid' => $pid];
}

function cleanupFakeServer(array $server): void
{
    pcntl_waitpid($server['pid'], $status, WNOHANG);
    posix_kill($server['pid'], SIGTERM);
    pcntl_waitpid($server['pid'], $status);
    if (file_exists($server['socketPath'])) {
        unlink($server['socketPath']);
    }
}

describe('SocketConnection', function () {

    it('throws when socket file does not exist', function () {
        expect(fn() => SocketConnection::send('/nonexistent/socket.sock', ['command' => 'ping']))
            ->toThrow(DaemonException::class, 'Socket not found');
    });

    it('throws when socket file is not a valid socket', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_fake_sock_');

        try {
            expect(fn() => SocketConnection::send($tmpFile, ['command' => 'ping'], 1.0))
                ->toThrow(DaemonException::class, 'Cannot connect to Unix socket');
        } finally {
            unlink($tmpFile);
        }
    })->skipOnWindows();

    it('throws on empty response when server closes without responding', function () {
        $server = createFakeServer(respond: false);

        try {
            expect(fn() => SocketConnection::send($server['socketPath'], ['command' => 'ping'], 2.0))
                ->toThrow(DaemonException::class, 'Empty response from daemon');
        } finally {
            cleanupFakeServer($server);
        }
    })->skipOnWindows();

    it('throws on timeout when server holds connection open without responding', function () {
        $server = createFakeServer(respond: false, delayMs: 3000);

        try {
            expect(fn() => SocketConnection::send($server['socketPath'], ['command' => 'ping'], 1.0))
                ->toThrow(DaemonException::class, 'Read timeout');
        } finally {
            cleanupFakeServer($server);
        }
    })->skipOnWindows();

    it('throws on invalid JSON response', function () {
        $server = createFakeServer(respond: true, response: 'not-json{{{');

        try {
            expect(fn() => SocketConnection::send($server['socketPath'], ['command' => 'ping'], 2.0))
                ->toThrow(JsonException::class);
        } finally {
            cleanupFakeServer($server);
        }
    })->skipOnWindows();

    it('throws on non-array JSON response', function () {
        $server = createFakeServer(respond: true, response: '"just a string"');

        try {
            expect(fn() => SocketConnection::send($server['socketPath'], ['command' => 'ping'], 2.0))
                ->toThrow(DaemonException::class, 'Invalid response from daemon');
        } finally {
            cleanupFakeServer($server);
        }
    })->skipOnWindows();

    it('returns decoded array on valid JSON response', function () {
        $server = createFakeServer(respond: true, response: json_encode(['success' => true, 'data' => 'ok']));

        try {
            $result = SocketConnection::send($server['socketPath'], ['command' => 'ping'], 2.0);
            expect($result)->toBe(['success' => true, 'data' => 'ok']);
        } finally {
            cleanupFakeServer($server);
        }
    })->skipOnWindows();

    it('sends JSON payload with newline delimiter', function () {
        $server = createFakeServer(respond: true, response: json_encode(['success' => true, 'data' => null]));

        try {
            $result = SocketConnection::send($server['socketPath'], ['command' => 'ping', 'extra' => 'value'], 2.0);
            expect($result['success'])->toBeTrue();
        } finally {
            cleanupFakeServer($server);
        }
    })->skipOnWindows();

    it('routes a tcp:// endpoint through TcpLoopbackTransport (refuses non-loopback)', function () {
        expect(fn () => SocketConnection::send('tcp://192.168.1.1:9990', ['command' => 'ping'], 0.5))
            ->toThrow(DaemonException::class, 'refuses to bind to non-loopback');
    });

    it('rejects an unsupported URI scheme', function () {
        expect(fn () => SocketConnection::send('http://localhost/endpoint', ['command' => 'ping']))
            ->toThrow(DaemonException::class, 'Unsupported transport scheme');
    });

});
