<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;

describe('SessionManagerDaemon: listener endpoint validation', function () {

    it('accepts a unix:// endpoint URI', function () {
        $d = new SessionManagerDaemon('unix:///tmp/opcua-sm-test.sock');
        expect($d)->toBeInstanceOf(SessionManagerDaemon::class);
    });

    it('accepts a scheme-less path (treated as Unix socket)', function () {
        $d = new SessionManagerDaemon('/tmp/opcua-sm-test-legacy.sock');
        expect($d)->toBeInstanceOf(SessionManagerDaemon::class);
    });

    it('accepts a tcp:// loopback endpoint', function () {
        $d = new SessionManagerDaemon('tcp://127.0.0.1:9990');
        expect($d)->toBeInstanceOf(SessionManagerDaemon::class);
    });

    it('accepts an IPv6 tcp:// loopback endpoint', function () {
        $d = new SessionManagerDaemon('tcp://[::1]:9991');
        expect($d)->toBeInstanceOf(SessionManagerDaemon::class);
    });

    it('refuses a tcp:// endpoint bound to a non-loopback host', function () {
        expect(fn () => new SessionManagerDaemon('tcp://192.168.1.1:9990'))
            ->toThrow(RuntimeException::class, 'refuses to bind TCP listener to non-loopback');
    });

    it('refuses a tcp:// endpoint bound to the wildcard address', function () {
        expect(fn () => new SessionManagerDaemon('tcp://0.0.0.0:9990'))
            ->toThrow(RuntimeException::class, 'refuses to bind TCP listener to non-loopback');
    });

    it('refuses a tcp:// endpoint pointing at a public hostname', function () {
        expect(fn () => new SessionManagerDaemon('tcp://example.com:9990'))
            ->toThrow(RuntimeException::class, 'refuses to bind TCP listener to non-loopback');
    });
});
