<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Tests\Helpers;

use PhpOpcua\SessionManager\Client\ManagedClient;
use ReflectionProperty;
use RuntimeException;

/**
 * Cross-OS fake daemon over TCP loopback for unit testing `ManagedClient`.
 *
 * Uses `proc_open()` + `tcp://127.0.0.1:0` instead of `pcntl_fork()` + `unix://`,
 * so it runs on Linux, macOS, and Windows.
 */
final class FakeTcpDaemon
{
    /**
     * @param array<int, array<string, mixed>> $responses JSON objects emitted in order, one per inbound frame.
     * @return array{endpoint: string, process: resource, pipes: array, scriptFile: string}
     */
    public static function start(array $responses): array
    {
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($listener === false) {
            throw new RuntimeException("Cannot open listener: [{$errno}] {$errstr}");
        }
        $name = stream_socket_get_name($listener, false);
        fclose($listener);
        if ($name === false) {
            throw new RuntimeException('Cannot resolve listener endpoint');
        }
        [$host, $port] = explode(':', $name);

        $responsesArg = base64_encode(serialize($responses));

        $script = <<<'PHP'
<?php
$responses = unserialize(base64_decode($argv[1]));
$host = $argv[2];
$port = (int) $argv[3];

$server = stream_socket_server("tcp://{$host}:{$port}");
if ($server === false) {
    exit(1);
}

// Ready-probe: accept the parent readiness connection and close it without
// consuming an entry from $responses.
$probe = @stream_socket_accept($server, 10);
if ($probe !== false) {
    fclose($probe);
}

foreach ($responses as $responseData) {
    $conn = @stream_socket_accept($server, 10);
    if ($conn === false) {
        break;
    }

    $data = '';
    while (!str_contains($data, "\n")) {
        $chunk = fread($conn, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }

    fwrite($conn, json_encode($responseData) . "\n");
    fclose($conn);
}

fclose($server);
exit(0);
PHP;

        $scriptFile = tempnam(sys_get_temp_dir(), 'opcua_fake_tcp_') . '.php';
        file_put_contents($scriptFile, $script);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, $scriptFile, $responsesArg, $host, $port],
            $descriptors,
            $pipes,
        );
        if (! is_resource($process)) {
            @unlink($scriptFile);

            throw new RuntimeException('Cannot spawn fake daemon subprocess');
        }

        set_error_handler(static fn (): bool => true);
        try {
            $probeConnected = false;
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                $probe = @stream_socket_client("tcp://{$host}:{$port}", $err, $errstr, 0.2);
                if ($probe !== false) {
                    fclose($probe);
                    $probeConnected = true;
                    break;
                }
                usleep(50_000);
            }
        } finally {
            restore_error_handler();
        }

        if (! $probeConnected) {
            @unlink($scriptFile);
            proc_terminate($process);
            proc_close($process);

            throw new RuntimeException("Fake daemon did not bind tcp://{$host}:{$port} within 3s");
        }

        return [
            'endpoint' => "tcp://{$host}:{$port}",
            'process' => $process,
            'pipes' => $pipes,
            'scriptFile' => $scriptFile,
        ];
    }

    /**
     * @param array{process: resource, pipes: array, scriptFile: string} $daemon
     * @return void
     */
    public static function stop(array $daemon): void
    {
        foreach ($daemon['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($daemon['process'])) {
            $status = proc_get_status($daemon['process']);
            if ($status['running']) {
                proc_terminate($daemon['process']);
                usleep(200_000);
                $status = proc_get_status($daemon['process']);
                if ($status['running']) {
                    proc_terminate($daemon['process'], 9);
                }
            }
            proc_close($daemon['process']);
        }
        if (isset($daemon['scriptFile']) && file_exists($daemon['scriptFile'])) {
            @unlink($daemon['scriptFile']);
        }
    }

    /**
     * @param string $endpoint
     * @param string $sessionId
     * @param float $timeout
     * @return ManagedClient
     */
    public static function connectClient(string $endpoint, string $sessionId = 'fake-session-id', float $timeout = 2.0): ManagedClient
    {
        $client = new ManagedClient($endpoint, timeout: $timeout);

        $ref = new ReflectionProperty(ManagedClient::class, 'sessionId');
        $ref->setValue($client, $sessionId);

        return $client;
    }
}
