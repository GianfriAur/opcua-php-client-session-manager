<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\Module\ModuleRegistry;
use PhpOpcua\Client\Module\ReadWrite\ReadWriteModule;
use PhpOpcua\Client\Module\Subscription\SubscriptionModule;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Wire\CoreWireTypes;
use PhpOpcua\Client\Wire\WireTypeRegistry;
use PhpOpcua\SessionManager\Daemon\CommandHandler;
use PhpOpcua\SessionManager\Daemon\Session;
use PhpOpcua\SessionManager\Daemon\SessionStore;

function wireEncode(WireTypeRegistry $registry, mixed $value): mixed
{
    return $registry->encode($value);
}

function clientStubWith(array $overrides = []): Client
{
    $client = test()->createStub(Client::class);

    $defaults = [
        'getRegisteredMethods' => ['read', 'browse', 'write', 'isConnected'],
        'getLoadedModules' => [ReadWriteModule::class, SubscriptionModule::class],
    ];
    foreach (array_merge($defaults, $overrides) as $name => $value) {
        $client->method($name)->willReturn($value);
    }

    return $client;
}

describe('CommandHandler: describe', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
        $this->handler = new CommandHandler($this->store);
    });

    it('returns methods, modules, and wire-type FQCNs for the session client', function () {
        $client = clientStubWith();
        $modules = new ModuleRegistry();
        $modules->add(new ReadWriteModule());
        $modules->add(new SubscriptionModule());
        $clientRef = new ReflectionClass(Client::class);
        $prop = $clientRef->getProperty('moduleRegistry');
        $prop->setAccessible(true);
        $prop->setValue($client, $modules);

        $this->store->create(new Session('s1', $client, 'opc.tcp://h:4840', [], microtime(true)));

        $res = $this->handler->handle(['command' => 'describe', 'sessionId' => 's1']);

        expect($res['success'])->toBeTrue();
        expect($res['data']['methods'])->toContain('read');
        expect($res['data']['modules'])->toContain(ReadWriteModule::class);
        expect($res['data']['wireClasses'])->toContain(NodeId::class);
        expect($res['data']['wireClasses'])->toContain(DataValue::class);
        expect($res['data']['wireClasses'])->toContain(Variant::class);
        expect($res['data']['enumClasses'])->toContain(BuiltinType::class);
        expect($res['data']['wireTypeIds'])->toContain('NodeId');
        expect($res['data']['wireTypeIds'])->toContain('DataValue');
    });

    it('reports session_not_found for unknown sessionId', function () {
        $res = $this->handler->handle(['command' => 'describe', 'sessionId' => 'missing']);
        expect($res['success'])->toBeFalse();
        expect($res['error']['type'])->toBe('session_not_found');
    });
});

describe('CommandHandler: invoke', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
        $this->handler = new CommandHandler($this->store);

        $this->registry = new WireTypeRegistry();
        CoreWireTypes::register($this->registry);
    });

    it('dispatches a method with a typed argument and returns the typed result', function () {
        $client = clientStubWith(['getRegisteredMethods' => ['read'], 'hasMethod' => true]);
        $dv = new DataValue(new Variant(BuiltinType::Int32, 42), 0);
        $client->method('read')->willReturn($dv);

        $modules = new ModuleRegistry();
        $modules->add(new ReadWriteModule());
        $prop = (new ReflectionClass(Client::class))->getProperty('moduleRegistry');
        $prop->setAccessible(true);
        $prop->setValue($client, $modules);

        $this->store->create(new Session('s1', $client, 'opc.tcp://h:4840', [], microtime(true)));

        $nodeId = NodeId::numeric(0, 2259);
        $res = $this->handler->handle([
            'command' => 'invoke',
            'sessionId' => 's1',
            'method' => 'read',
            'args' => [wireEncode($this->registry, $nodeId), 13, false],
        ]);

        expect($res['success'])->toBeTrue();
        expect($res['data']['data'])->toBeArray();
        expect($res['data']['data']['__t'])->toBe('DataValue');
        expect($res['data']['data']['status'])->toBe(0);
    });

    it('rejects a method that is not registered on the client', function () {
        $client = clientStubWith(['hasMethod' => false]);
        $this->store->create(new Session('s2', $client, 'opc.tcp://h:4840', [], microtime(true)));

        $res = $this->handler->handle([
            'command' => 'invoke',
            'sessionId' => 's2',
            'method' => 'nonExistent',
            'args' => [],
        ]);

        expect($res['success'])->toBeFalse();
        expect($res['error']['type'])->toBe('unknown_method');
    });

    it('rejects an invoke with a non-string method', function () {
        $client = clientStubWith();
        $this->store->create(new Session('s3', $client, 'opc.tcp://h:4840', [], microtime(true)));

        $res = $this->handler->handle([
            'command' => 'invoke',
            'sessionId' => 's3',
            'method' => 123,
            'args' => [],
        ]);

        expect($res['success'])->toBeFalse();
        expect($res['error']['type'])->toBe('invalid_argument');
    });

    it('rejects an invoke with non-array args', function () {
        $client = clientStubWith();
        $this->store->create(new Session('s4', $client, 'opc.tcp://h:4840', [], microtime(true)));

        $res = $this->handler->handle([
            'command' => 'invoke',
            'sessionId' => 's4',
            'method' => 'read',
            'args' => 'not-an-array',
        ]);

        expect($res['success'])->toBeFalse();
        expect($res['error']['type'])->toBe('invalid_argument');
    });
});
