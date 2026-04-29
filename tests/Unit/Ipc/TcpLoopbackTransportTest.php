<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Exception\DaemonException;
use PhpOpcua\SessionManager\Ipc\TcpLoopbackTransport;

describe('TcpLoopbackTransport', function () {

    it('accepts 127.0.0.1 and describes the IPv4 endpoint', function () {
        $t = new TcpLoopbackTransport('127.0.0.1', 9990);
        expect($t->describeEndpoint())->toBe('tcp://127.0.0.1:9990');
    });

    it('accepts ::1 and describes the IPv6 endpoint with brackets', function () {
        $t = new TcpLoopbackTransport('::1', 9991);
        expect($t->describeEndpoint())->toBe('tcp://[::1]:9991');
    });

    it('accepts 127.x.y.z loopback space', function () {
        $t = new TcpLoopbackTransport('127.0.0.2', 9992);
        expect($t->describeEndpoint())->toBe('tcp://127.0.0.2:9992');
    });

    it('accepts IPv4-mapped IPv6 loopback (::ffff:127.0.0.1)', function () {
        $t = new TcpLoopbackTransport('::ffff:127.0.0.1', 9993);
        expect($t->describeEndpoint())->toBe('tcp://[::ffff:127.0.0.1]:9993');
    });

    it('rejects IPv4-mapped IPv6 non-loopback (::ffff:192.168.1.10)', function () {
        expect(fn () => new TcpLoopbackTransport('::ffff:192.168.1.10', 9990))
            ->toThrow(DaemonException::class, 'refuses to bind to non-loopback');
    });

    it('rejects a non-loopback IPv4 address at construction', function () {
        expect(fn () => new TcpLoopbackTransport('192.168.1.10', 9990))
            ->toThrow(DaemonException::class, 'refuses to bind to non-loopback');
    });

    it('rejects a public hostname at construction', function () {
        expect(fn () => new TcpLoopbackTransport('example.com', 9990))
            ->toThrow(DaemonException::class, 'refuses to bind to non-loopback');
    });

    it('rejects 0.0.0.0 wildcard at construction', function () {
        expect(fn () => new TcpLoopbackTransport('0.0.0.0', 9990))
            ->toThrow(DaemonException::class, 'refuses to bind to non-loopback');
    });

    it('surfaces a DaemonException when the port is closed', function () {
        $t = new TcpLoopbackTransport('127.0.0.1', 1, 0.5);
        expect(fn () => $t->connect())
            ->toThrow(DaemonException::class, 'Cannot connect to TCP loopback');
    });
});
