<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaSessionManager\Daemon\CommandHandler;
use Gianfriaur\OpcuaSessionManager\Daemon\Session;
use Gianfriaur\OpcuaSessionManager\Daemon\SessionStore;

describe('CommandHandler Extended', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
        $this->handler = new CommandHandler($this->store);
    });

    describe('handleClose', function () {

        it('closes an existing session', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle(['command' => 'close', 'sessionId' => 's1']);

            expect($result['success'])->toBeTrue();
            expect($result['data'])->toBeNull();
            expect($this->store->count())->toBe(0);
        });

        it('closes session even when disconnect throws', function () {
            $client = $this->createStub(Client::class);
            $client->method('disconnect')->willThrowException(new RuntimeException('disconnect failed'));
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle(['command' => 'close', 'sessionId' => 's1']);

            expect($result['success'])->toBeTrue();
            expect($this->store->count())->toBe(0);
        });

        it('returns session_not_found for non-existent session', function () {
            $result = $this->handler->handle(['command' => 'close', 'sessionId' => 'nonexistent']);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('session_not_found');
        });

    });

    describe('handleOpen error paths', function () {

        it('returns error on connect failure', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => [],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies opcuaTimeout config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => ['opcuaTimeout' => 0.1],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies autoRetry config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => ['autoRetry' => 0, 'opcuaTimeout' => 0.1],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies batchSize config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => ['batchSize' => 50, 'opcuaTimeout' => 0.1],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies defaultBrowseMaxDepth config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => ['defaultBrowseMaxDepth' => 20, 'opcuaTimeout' => 0.1],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies securityPolicy and securityMode config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => [
                    'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#None',
                    'securityMode' => 1,
                    'opcuaTimeout' => 0.1,
                ],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies username/password config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => [
                    'username' => 'admin',
                    'password' => 'secret',
                    'opcuaTimeout' => 0.1,
                ],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies clientCache when configured', function () {
            $cache = $this->createStub(\Psr\SimpleCache\CacheInterface::class);
            $handler = new CommandHandler($this->store, clientCache: $cache);

            $result = $handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => ['opcuaTimeout' => 0.1],
            ]);

            expect($result['success'])->toBeFalse();
        });

    });

    describe('Error sanitization', function () {

        it('sanitizes error messages from generic Throwable', function () {
            $client = $this->createStub(Client::class);
            $client->method('read')->willThrowException(
                new RuntimeException('Error at /home/user/secret/path.php: failed')
            );
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'read',
                'params' => [['ns' => 0, 'id' => 2259, 'type' => 'numeric'], 13],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['message'])->toContain('[path]');
            expect($result['error']['message'])->not->toContain('/home/user/secret');
        });

    });

    describe('Certificate validation in handleOpen', function () {

        it('rejects userCertPath that does not exist', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://localhost:4840',
                'config' => [
                    'userCertPath' => '/nonexistent/user-cert.pem',
                    'userKeyPath' => '/nonexistent/user-key.pem',
                ],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['message'])->toContain('does not exist');
        });

        it('validates allowedCertDirs path resolution failure', function () {
            $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_');
            file_put_contents($tmpFile, 'fake cert');

            try {
                $handler = new CommandHandler($this->store, allowedCertDirs: ['/nonexistent/allowed']);

                $result = $handler->handle([
                    'command' => 'open',
                    'endpointUrl' => 'opc.tcp://localhost:4840',
                    'config' => [
                        'clientCertPath' => $tmpFile,
                        'clientKeyPath' => $tmpFile,
                    ],
                ]);

                expect($result['success'])->toBeFalse();
                expect($result['error']['message'])->toContain('not in an allowed directory');
            } finally {
                unlink($tmpFile);
            }
        });

    });

});
