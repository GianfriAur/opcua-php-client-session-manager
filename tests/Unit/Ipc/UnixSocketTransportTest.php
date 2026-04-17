<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Exception\DaemonException;
use PhpOpcua\SessionManager\Ipc\UnixSocketTransport;

describe('UnixSocketTransport', function () {

    it('describes the endpoint as unix://<path>', function () {
        $t = new UnixSocketTransport('/tmp/example.sock');
        expect($t->describeEndpoint())->toBe('unix:///tmp/example.sock');
    });

    it('surfaces a DaemonException when the socket does not exist', function () {
        $path = sys_get_temp_dir() . '/opcua-test-missing-' . uniqid() . '.sock';
        $t = new UnixSocketTransport($path, 0.5);
        expect(fn () => $t->connect())
            ->toThrow(DaemonException::class, 'Cannot connect to Unix socket');
    });
});
