<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Daemon\CommandHandler;
use PhpOpcua\SessionManager\Daemon\SessionStore;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Exercises every conditional branch in `CommandHandler::handleOpen()` that
 * forwards config values to the underlying `ClientBuilder`. The `connect()`
 * call itself fails (no real OPC UA server), which is expected: the goal is to
 * reach each setter on the builder before the failure.
 */
describe('CommandHandler handleOpen setup branches', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
    });

    it('routes eventDispatcher and cache onto the builder before connect', function () {
        $dispatcher = new class() implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $cache = new class() implements CacheInterface {
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool { return true; }
            public function delete(string $key): bool { return true; }
            public function clear(): bool { return true; }
            public function getMultiple(iterable $keys, mixed $default = null): iterable { return []; }
            public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool { return true; }
            public function deleteMultiple(iterable $keys): bool { return true; }
            public function has(string $key): bool { return false; }
        };

        $handler = new CommandHandler(
            store: $this->store,
            clientCache: $cache,
            clientEventDispatcher: $dispatcher,
        );

        set_error_handler(static fn (): bool => true);
        try {
        $result = $handler->handle([
            'command' => 'open',
            'endpointUrl' => 'opc.tcp://127.0.0.1:1',
            'config' => [],
        ]);

        expect($result['success'])->toBeFalse();
        } finally {
            restore_error_handler();
        }
    });

    it('invokes every optional setter branch before failing on connect', function () {
        $handler = new CommandHandler(store: $this->store);

        set_error_handler(static fn (): bool => true);
        try {
        $result = $handler->handle([
            'command' => 'open',
            'endpointUrl' => 'opc.tcp://127.0.0.1:1',
            'config' => [
                'opcuaTimeout' => 10.0,
                'autoRetry' => 3,
                'batchSize' => 100,
                'defaultBrowseMaxDepth' => 5,
                'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#None',
                'securityMode' => 1,
                'trustPolicy' => 'fingerprint',
                'autoAccept' => true,
                'autoAcceptForce' => false,
                'autoDetectWriteType' => true,
                'readMetadataCache' => true,
            ],
        ]);

        expect($result['success'])->toBeFalse();
        } finally {
            restore_error_handler();
        }
    });

});
