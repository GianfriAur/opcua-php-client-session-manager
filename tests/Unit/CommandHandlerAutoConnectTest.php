<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\Module\Subscription\SubscriptionResult;
use PhpOpcua\SessionManager\Daemon\CommandHandler;
use PhpOpcua\SessionManager\Daemon\Session;
use PhpOpcua\SessionManager\Daemon\SessionStore;

describe('CommandHandler::autoConnectSession', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
        $this->handler = new CommandHandler(store: $this->store);
    });

    it('returns null when handleOpen fails (max sessions reached)', function () {
        $handler = new CommandHandler(store: $this->store, maxSessions: 1);
        $client = $this->createStub(Client::class);
        $session = new Session('existing', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $handler->autoConnectSession(
            'opc.tcp://another.example:4840',
            [],
            [],
        );

        expect($result)->toBeNull();
    });

    it('creates subscriptions + monitored items on a pre-existing session', function () {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('createSubscription')
            ->willReturn(new SubscriptionResult(42, 500.0, 2400, 10));
        $client->expects($this->once())
            ->method('createMonitoredItems')
            ->with(42, $this->callback(fn ($items) => count($items) === 1 && $items[0]['nodeId'] === 'ns=2;i=1001'));
        $client->expects($this->once())
            ->method('createEventMonitoredItem')
            ->with(42, 'ns=0;i=2253', $this->anything(), 7);

        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $sessionId = $this->handler->autoConnectSession(
            'opc.tcp://localhost:4840',
            [],
            [
                [
                    'publishing_interval' => 500.0,
                    'lifetime_count' => 2400,
                    'max_keep_alive_count' => 10,
                    'priority' => 1,
                    'monitored_items' => [
                        [
                            'node_id' => 'ns=2;i=1001',
                            'attribute_id' => 13,
                            'sampling_interval' => 250.0,
                            'queue_size' => 1,
                            'client_handle' => 1,
                        ],
                    ],
                    'event_monitored_items' => [
                        [
                            'node_id' => 'ns=0;i=2253',
                            'select_fields' => ['EventId', 'Severity'],
                            'client_handle' => 7,
                        ],
                    ],
                ],
            ],
        );

        expect($sessionId)->toBe('s1');
    });

    it('skips monitored-items path when none are configured', function () {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('createSubscription')
            ->willReturn(new SubscriptionResult(99, 500.0, 2400, 10));
        $client->expects($this->never())->method('createMonitoredItems');
        $client->expects($this->never())->method('createEventMonitoredItem');

        $session = new Session('s2', $client, 'opc.tcp://localhost:4841', [], microtime(true));
        $this->store->create($session);

        $sessionId = $this->handler->autoConnectSession(
            'opc.tcp://localhost:4841',
            [],
            [['publishing_interval' => 500.0]],
        );

        expect($sessionId)->toBe('s2');
    });

});
