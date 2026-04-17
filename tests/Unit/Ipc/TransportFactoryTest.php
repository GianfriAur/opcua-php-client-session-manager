<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Exception\DaemonException;
use PhpOpcua\SessionManager\Ipc\TcpLoopbackTransport;
use PhpOpcua\SessionManager\Ipc\TransportFactory;
use PhpOpcua\SessionManager\Ipc\UnixSocketTransport;

describe('TransportFactory: scheme routing', function () {

    it('routes an explicit unix:// URI to UnixSocketTransport', function () {
        $t = TransportFactory::create('unix:///tmp/example.sock');
        expect($t)->toBeInstanceOf(UnixSocketTransport::class);
        expect($t->describeEndpoint())->toBe('unix:///tmp/example.sock');
    });

    it('routes a scheme-less path to UnixSocketTransport (BC with pre-v4.2.0)', function () {
        $t = TransportFactory::create('/tmp/legacy.sock');
        expect($t)->toBeInstanceOf(UnixSocketTransport::class);
        expect($t->describeEndpoint())->toBe('unix:///tmp/legacy.sock');
    });

    it('routes an IPv4 tcp:// URI to TcpLoopbackTransport', function () {
        $t = TransportFactory::create('tcp://127.0.0.1:9990');
        expect($t)->toBeInstanceOf(TcpLoopbackTransport::class);
        expect($t->describeEndpoint())->toBe('tcp://127.0.0.1:9990');
    });

    it('routes an IPv6 tcp:// URI to TcpLoopbackTransport with bracketed host', function () {
        $t = TransportFactory::create('tcp://[::1]:9991');
        expect($t)->toBeInstanceOf(TcpLoopbackTransport::class);
        expect($t->describeEndpoint())->toBe('tcp://[::1]:9991');
    });

    it('refuses an unsupported scheme', function () {
        expect(fn () => TransportFactory::create('http://example/foo'))
            ->toThrow(DaemonException::class, 'Unsupported transport scheme');
    });

    it('refuses a non-loopback TCP host (guarded by TcpLoopbackTransport)', function () {
        expect(fn () => TransportFactory::create('tcp://192.168.1.10:9990'))
            ->toThrow(DaemonException::class, 'refuses to bind to non-loopback');
    });

    it('refuses a TCP URI with no port', function () {
        expect(fn () => TransportFactory::create('tcp://127.0.0.1'))
            ->toThrow(DaemonException::class, 'missing port');
    });

    it('refuses a TCP URI with port out of range', function () {
        expect(fn () => TransportFactory::create('tcp://127.0.0.1:99999'))
            ->toThrow(DaemonException::class, 'Invalid TCP endpoint');
    });

    it('refuses a malformed IPv6 authority', function () {
        expect(fn () => TransportFactory::create('tcp://[::1'))
            ->toThrow(DaemonException::class, 'Malformed IPv6');
    });

    it('refuses a TCP URI with missing host:port', function () {
        expect(fn () => TransportFactory::create('tcp://'))
            ->toThrow(DaemonException::class, 'missing host:port');
    });
});

describe('TransportFactory: introspection helpers', function () {

    it('defaultEndpoint picks per-platform', function () {
        $default = TransportFactory::defaultEndpoint();
        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            expect($default)->toStartWith('tcp://127.0.0.1:');
        } else {
            expect($default)->toBe('unix:///tmp/opcua-session-manager.sock');
        }
    });

    it('isUnixEndpoint recognises unix scheme and scheme-less paths', function () {
        expect(TransportFactory::isUnixEndpoint('unix:///tmp/foo.sock'))->toBeTrue();
        expect(TransportFactory::isUnixEndpoint('/tmp/foo.sock'))->toBeTrue();
        expect(TransportFactory::isUnixEndpoint('tcp://127.0.0.1:9990'))->toBeFalse();
        expect(TransportFactory::isUnixEndpoint('http://example/foo'))->toBeFalse();
    });

    it('toUnixPath extracts the path from unix:// URIs and scheme-less paths', function () {
        expect(TransportFactory::toUnixPath('unix:///tmp/a.sock'))->toBe('/tmp/a.sock');
        expect(TransportFactory::toUnixPath('/tmp/b.sock'))->toBe('/tmp/b.sock');
    });

    it('toUnixPath returns null for TCP endpoints', function () {
        expect(TransportFactory::toUnixPath('tcp://127.0.0.1:9990'))->toBeNull();
    });
});
