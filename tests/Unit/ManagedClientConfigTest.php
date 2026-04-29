<?php

declare(strict_types=1);

use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\SessionManager\Client\ManagedClient;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;

describe('ManagedClient Configuration', function () {

    describe('Timeout', function () {

        it('has default timeout of 5.0', function () {
            $client = new ManagedClient();
            expect($client->getTimeout())->toBe(5.0);
        });

        it('sets timeout with fluent chaining', function () {
            $client = new ManagedClient();
            $result = $client->setTimeout(10.0);

            expect($result)->toBe($client);
            expect($client->getTimeout())->toBe(10.0);
        });

        it('accepts fractional timeouts', function () {
            $client = new ManagedClient();
            $client->setTimeout(2.5);

            expect($client->getTimeout())->toBe(2.5);
        });

    });

    describe('Auto-retry', function () {

        it('returns 0 when not connected and not configured', function () {
            $client = new ManagedClient();
            expect($client->getAutoRetry())->toBe(0);
        });

        it('sets auto-retry with fluent chaining', function () {
            $client = new ManagedClient();
            $result = $client->setAutoRetry(3);

            expect($result)->toBe($client);
            expect($client->getAutoRetry())->toBe(3);
        });

        it('overrides default when explicitly set to 0', function () {
            $client = new ManagedClient();
            $client->setAutoRetry(0);

            expect($client->getAutoRetry())->toBe(0);
        });

    });

    describe('Batching', function () {

        it('returns null batch size by default', function () {
            $client = new ManagedClient();
            expect($client->getBatchSize())->toBeNull();
        });

        it('sets batch size with fluent chaining', function () {
            $client = new ManagedClient();
            $result = $client->setBatchSize(100);

            expect($result)->toBe($client);
            expect($client->getBatchSize())->toBe(100);
        });

        it('allows setting batch size to 0 (disable)', function () {
            $client = new ManagedClient();
            $client->setBatchSize(0);

            expect($client->getBatchSize())->toBe(0);
        });

    });

    describe('Browse depth', function () {

        it('returns default browse max depth of 10', function () {
            $client = new ManagedClient();
            expect($client->getDefaultBrowseMaxDepth())->toBe(10);
        });

        it('sets browse max depth with fluent chaining', function () {
            $client = new ManagedClient();
            $result = $client->setDefaultBrowseMaxDepth(20);

            expect($result)->toBe($client);
            expect($client->getDefaultBrowseMaxDepth())->toBe(20);
        });

        it('allows unlimited depth (-1)', function () {
            $client = new ManagedClient();
            $client->setDefaultBrowseMaxDepth(-1);

            expect($client->getDefaultBrowseMaxDepth())->toBe(-1);
        });

    });

    describe('Connection state (local)', function () {

        it('returns Disconnected when no session', function () {
            $client = new ManagedClient();
            expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
        });

        it('returns false for isConnected when no session', function () {
            $client = new ManagedClient();
            expect($client->isConnected())->toBeFalse();
        });

        it('returns null session ID when not connected', function () {
            $client = new ManagedClient();
            expect($client->getSessionId())->toBeNull();
        });

    });

    describe('Logger', function () {

        it('returns NullLogger by default', function () {
            $client = new ManagedClient();
            expect($client->getLogger())->toBeInstanceOf(NullLogger::class);
        });

        it('sets logger with fluent chaining', function () {
            $client = new ManagedClient();
            $logger = new NullLogger();
            $result = $client->setLogger($logger);

            expect($result)->toBe($client);
            expect($client->getLogger())->toBe($logger);
        });

    });

    describe('Cache', function () {

        it('returns null cache by default', function () {
            $client = new ManagedClient();
            expect($client->getCache())->toBeNull();
        });

        it('sets cache with fluent chaining', function () {
            $client = new ManagedClient();
            $result = $client->setCache(null);

            expect($result)->toBe($client);
        });

    });

    describe('ExtensionObjectRepository', function () {

        it('returns an ExtensionObjectRepository instance', function () {
            $client = new ManagedClient();
            expect($client->getExtensionObjectRepository())->toBeInstanceOf(ExtensionObjectRepository::class);
        });

    });

    describe('Event dispatcher', function () {

        it('returns the eventDispatcher after setting it', function () {
            $client = new ManagedClient();
            $dispatcher = new class() implements EventDispatcherInterface {
                public function dispatch(object $event): object
                {
                    return $event;
                }
            };

            $result = $client->setEventDispatcher($dispatcher);

            expect($result)->toBe($client);
            expect($client->getEventDispatcher())->toBe($dispatcher);
        });

    });

    describe('Trust store / policy', function () {

        it('returns null trust store (not exposed via ManagedClient)', function () {
            $client = new ManagedClient();
            expect($client->getTrustStore())->toBeNull();
        });

        it('returns the configured trust policy (null by default)', function () {
            $client = new ManagedClient();
            expect($client->getTrustPolicy())->toBeNull();
        });

        it('sets trustStorePath fluently', function () {
            $client = new ManagedClient();
            $result = $client->setTrustStorePath('/etc/opcua/trust');

            expect($result)->toBe($client);
        });

        it('sets trustPolicy fluently', function () {
            $client = new ManagedClient();
            $result = $client->setTrustPolicy(TrustPolicy::Fingerprint);

            expect($result)->toBe($client);
            expect($client->getTrustPolicy())->toBe(TrustPolicy::Fingerprint);
        });

        it('accepts null trustPolicy (reset)', function () {
            $client = new ManagedClient();
            $client->setTrustPolicy(TrustPolicy::Fingerprint);
            $client->setTrustPolicy(null);

            expect($client->getTrustPolicy())->toBeNull();
        });

    });

    describe('Auto-accept', function () {

        it('enables auto-accept with default force=false', function () {
            $client = new ManagedClient();
            $result = $client->autoAccept();

            expect($result)->toBe($client);
        });

        it('enables auto-accept with force=true', function () {
            $client = new ManagedClient();
            $result = $client->autoAccept(true, true);

            expect($result)->toBe($client);
        });

        it('disables auto-accept', function () {
            $client = new ManagedClient();
            $result = $client->autoAccept(false);

            expect($result)->toBe($client);
        });

    });

    describe('Behavior flags', function () {

        it('sets autoDetectWriteType fluently', function () {
            $client = new ManagedClient();
            $result = $client->setAutoDetectWriteType(false);

            expect($result)->toBe($client);
        });

        it('sets readMetadataCache fluently', function () {
            $client = new ManagedClient();
            $result = $client->setReadMetadataCache(true);

            expect($result)->toBe($client);
        });

    });

    describe('Session introspection', function () {

        it('returns false for wasSessionReused when no session is open', function () {
            $client = new ManagedClient();
            expect($client->wasSessionReused())->toBeFalse();
        });

    });

    describe('Describe introspection (no-session fallback)', function () {

        it('hasMethod falls back to method_exists without a live session', function () {
            $client = new ManagedClient();
            expect($client->hasMethod('read'))->toBeTrue();
            expect($client->hasMethod('browse'))->toBeTrue();
            expect($client->hasMethod('noSuchMethodAnywhere_12345'))->toBeFalse();
        });

        it('hasModule returns false without a live session', function () {
            $client = new ManagedClient();
            expect($client->hasModule('PhpOpcua\\Client\\Module\\Browse\\BrowseModule'))->toBeFalse();
        });

        it('getRegisteredMethods falls back to the OpcUaClientInterface surface', function () {
            $client = new ManagedClient();
            $names = $client->getRegisteredMethods();

            expect($names)->toBeArray();
            expect($names)->not->toBeEmpty();
            expect($names)->toContain('read');
            expect($names)->toContain('browse');
        });

        it('getLoadedModules returns an empty array without a live session', function () {
            $client = new ManagedClient();
            expect($client->getLoadedModules())->toBe([]);
        });

    });

});
