<?php

declare(strict_types=1);

use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;
use Gianfriaur\OpcuaSessionManager\Exception\DaemonException;

describe('SocketConnection', function () {

    it('throws when socket file does not exist', function () {
        expect(fn() => SocketConnection::send('/nonexistent/socket.sock', ['command' => 'ping']))
            ->toThrow(DaemonException::class, 'Socket not found');
    });

    it('throws when socket file is not a valid socket', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_fake_sock_');

        try {
            expect(fn() => SocketConnection::send($tmpFile, ['command' => 'ping'], 1.0))
                ->toThrow(DaemonException::class, 'Cannot connect to daemon');
        } finally {
            unlink($tmpFile);
        }
    });

});
