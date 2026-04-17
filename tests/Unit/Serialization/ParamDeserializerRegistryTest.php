<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Serialization\BuiltInParamDeserializer;
use PhpOpcua\SessionManager\Serialization\ParamDeserializerInterface;
use PhpOpcua\SessionManager\Serialization\ParamDeserializerRegistry;
use PhpOpcua\SessionManager\Serialization\TypeSerializer;

final class FakeAcmeDeserializer implements ParamDeserializerInterface
{
    public function supports(string $method): bool
    {
        return $method === 'acme.queryFirst';
    }

    public function deserialize(string $method, array $params): array
    {
        return [(string) ($params[0] ?? ''), (int) ($params[1] ?? 0)];
    }
}

describe('ParamDeserializerRegistry', function () {

    beforeEach(function () {
        $this->registry = new ParamDeserializerRegistry();
    });

    it('delegates to a registered deserializer that supports the method', function () {
        $this->registry->register(new FakeAcmeDeserializer());

        $args = $this->registry->deserialize('acme.queryFirst', ['hello', '42']);

        expect($args)->toBe(['hello', 42]);
    });

    it('throws when no registered deserializer handles the method', function () {
        expect(fn () => $this->registry->deserialize('unknown.method', []))
            ->toThrow(InvalidArgumentException::class, 'No ParamDeserializer registered');
    });

    it('consults deserializers in registration order (first match wins)', function () {
        $first = new class implements ParamDeserializerInterface {
            public function supports(string $method): bool
            {
                return $method === 'shared';
            }

            public function deserialize(string $method, array $params): array
            {
                return ['first'];
            }
        };
        $second = new class implements ParamDeserializerInterface {
            public function supports(string $method): bool
            {
                return $method === 'shared';
            }

            public function deserialize(string $method, array $params): array
            {
                return ['second'];
            }
        };

        $this->registry->register($first);
        $this->registry->register($second);

        expect($this->registry->deserialize('shared', []))->toBe(['first']);
    });

    it('supports() returns true iff at least one deserializer handles the method', function () {
        $this->registry->register(new FakeAcmeDeserializer());

        expect($this->registry->supports('acme.queryFirst'))->toBeTrue();
        expect($this->registry->supports('acme.unknown'))->toBeFalse();
    });
});

describe('BuiltInParamDeserializer', function () {

    beforeEach(function () {
        $this->deserializer = new BuiltInParamDeserializer(new TypeSerializer());
    });

    it('supports every method shipped in the default whitelist', function () {
        $shipped = [
            'getEndpoints', 'browse', 'browseAll', 'browseWithContinuation', 'browseRecursive',
            'browseNext', 'translateBrowsePaths', 'resolveNodeId',
            'read', 'readMulti', 'write', 'writeMulti', 'call',
            'createSubscription', 'createMonitoredItems', 'createEventMonitoredItem',
            'deleteMonitoredItems', 'deleteSubscription', 'publish', 'transferSubscriptions', 'republish',
            'historyReadRaw', 'historyReadProcessed', 'historyReadAtTime',
            'discoverDataTypes', 'invalidateCache', 'modifyMonitoredItems', 'setTriggering',
            'trustCertificate', 'untrustCertificate',
            'isConnected', 'getConnectionState', 'reconnect',
            'getTimeout', 'getAutoRetry', 'getBatchSize',
            'getDefaultBrowseMaxDepth', 'getServerMaxNodesPerRead', 'getServerMaxNodesPerWrite',
            'flushCache', 'getTrustStore', 'getTrustPolicy', 'getEventDispatcher', 'getLogger',
        ];

        foreach ($shipped as $method) {
            expect($this->deserializer->supports($method))
                ->toBeTrue("expected BuiltInParamDeserializer to support '{$method}'");
        }
    });

    it('does not support an unknown method', function () {
        expect($this->deserializer->supports('acme.queryFirst'))->toBeFalse();
    });

    it('returns an empty arg list for no-argument config getters', function () {
        expect($this->deserializer->deserialize('getTimeout', []))->toBe([]);
        expect($this->deserializer->deserialize('isConnected', []))->toBe([]);
        expect($this->deserializer->deserialize('flushCache', []))->toBe([]);
    });

    it('deserializes getEndpoints args with sensible defaults', function () {
        expect($this->deserializer->deserialize('getEndpoints', ['opc.tcp://h:4840']))
            ->toBe(['opc.tcp://h:4840', true]);
        expect($this->deserializer->deserialize('getEndpoints', ['opc.tcp://h:4840', false]))
            ->toBe(['opc.tcp://h:4840', false]);
    });

    it('throws on an unsupported method', function () {
        expect(fn () => $this->deserializer->deserialize('acme.queryFirst', []))
            ->toThrow(InvalidArgumentException::class, 'Unsupported method: acme.queryFirst');
    });
});
