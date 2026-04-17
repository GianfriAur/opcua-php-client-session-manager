<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Exception\DaemonException;
use PhpOpcua\SessionManager\Ipc\AbstractStreamTransport;

/**
 * Concrete test double that opens a pre-paired `stream_socket_pair()` end
 * instead of touching the filesystem or network. The "peer" end is exposed so
 * tests can drive it to simulate the daemon side.
 */
final class PairedStreamTransport extends AbstractStreamTransport
{
    /** @var ?resource */
    public $peer = null;

    /** @var ?resource */
    private $preparedLocal = null;

    public function __construct(float $timeout = 5.0)
    {
        parent::__construct($timeout);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('stream_socket_pair failed: unsupported on this platform.');
        }
        [$this->preparedLocal, $this->peer] = $pair;
    }

    protected function openStream()
    {
        if ($this->preparedLocal === null) {
            throw new DaemonException('PairedStreamTransport has no prepared local stream.');
        }
        $s = $this->preparedLocal;
        $this->preparedLocal = null;

        return $s;
    }

    public function describeEndpoint(): string
    {
        return 'pair://test';
    }
}

describe('AbstractStreamTransport: NDJSON framing', function () {

    it('send + receive round-trip a single line', function () {
        $t = new PairedStreamTransport();
        $t->connect();

        $t->sendLine('{"hello":"world"}');

        $received = fgets($t->peer);
        expect($received)->toBe("{\"hello\":\"world\"}\n");

        $t->close();
        @fclose($t->peer);
    });

    it('receive reads up to the next \\n terminator', function () {
        $t = new PairedStreamTransport();
        $t->connect();

        fwrite($t->peer, "line1\nline2\n");

        expect($t->receiveLine())->toBe('line1');
        expect($t->receiveLine())->toBe('line2');

        $t->close();
        @fclose($t->peer);
    });

    it('refuses to send a payload containing a raw newline', function () {
        $t = new PairedStreamTransport();
        $t->connect();

        expect(fn () => $t->sendLine("bad\npayload"))
            ->toThrow(DaemonException::class, 'payload contains a raw newline byte');

        $t->close();
        @fclose($t->peer);
    });

    it('returns null on clean EOF from the peer', function () {
        $t = new PairedStreamTransport();
        $t->connect();

        fclose($t->peer);

        expect($t->receiveLine())->toBeNull();

        $t->close();
    });

    it('throws on sendLine before connect()', function () {
        $t = new PairedStreamTransport();
        expect(fn () => $t->sendLine('ping'))
            ->toThrow(DaemonException::class, 'disconnected transport');
    });

    it('throws on receiveLine before connect()', function () {
        $t = new PairedStreamTransport();
        expect(fn () => $t->receiveLine())
            ->toThrow(DaemonException::class, 'disconnected transport');
    });

    it('connect is idempotent', function () {
        $t = new PairedStreamTransport();
        $t->connect();
        $t->connect();
        expect($t->isConnected())->toBeTrue();
        $t->close();
        @fclose($t->peer);
    });

    it('close is idempotent', function () {
        $t = new PairedStreamTransport();
        $t->connect();
        $t->close();
        $t->close();
        expect($t->isConnected())->toBeFalse();
        @fclose($t->peer);
    });

    it('preserves binary bytes in the payload (no text-mode translation)', function () {
        $t = new PairedStreamTransport();
        $t->connect();

        $binary = "payload-with-\\x00-and-\\xff-bytes";
        $t->sendLine($binary);

        $received = fgets($t->peer);
        expect($received)->toBe($binary . "\n");

        $t->close();
        @fclose($t->peer);
    });
});
