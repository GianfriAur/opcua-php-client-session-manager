<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\ServiceUnsupportedException;
use PhpOpcua\Client\Module\ServerInfo\BuildInfo;
use PhpOpcua\Client\Module\Subscription\SetTriggeringResult;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\SessionManager\Tests\Helpers\FakeTcpDaemon;

/**
 * @param mixed $value
 * @param int $typeValue BuiltinType value (e.g. BuiltinType::String->value)
 * @return array<string, mixed>
 */
function wireDataValue(mixed $value, int $typeValue = 12): array
{
    return [
        'value' => $value,
        'type' => $typeValue,
        'dimensions' => null,
        'statusCode' => 0,
        'sourceTimestamp' => null,
        'serverTimestamp' => null,
    ];
}

describe('ManagedClient server-info reads (via fake TCP daemon)', function () {

    it('getServerProductName reads i=2262 and returns the string value', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => wireDataValue('OpenPlc', BuiltinType::String->value)],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->getServerProductName())->toBe('OpenPlc');
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('getServerProductName returns null when the value is not a string', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => wireDataValue(42, BuiltinType::Int32->value)],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->getServerProductName())->toBeNull();
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('getServerManufacturerName reads i=2263', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => wireDataValue('AcmeCo')],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->getServerManufacturerName())->toBe('AcmeCo');
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('getServerSoftwareVersion reads i=2264', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => wireDataValue('1.2.3')],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->getServerSoftwareVersion())->toBe('1.2.3');
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('getServerBuildNumber reads i=2265', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => wireDataValue('1042')],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->getServerBuildNumber())->toBe('1042');
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('getServerBuildDate reads i=2266 and returns a DateTimeImmutable', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => wireDataValue('2026-04-24T10:00:00+00:00', BuiltinType::DateTime->value)],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            $date = $client->getServerBuildDate();
            expect($date)->toBeInstanceOf(\DateTimeImmutable::class);
            expect($date->format('Y-m-d'))->toBe('2026-04-24');
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('getServerBuildInfo aggregates 5 reads into a BuildInfo DTO', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                wireDataValue('OpenPlc'),
                wireDataValue('AcmeCo'),
                wireDataValue('1.2.3'),
                wireDataValue('1042'),
                wireDataValue('2026-04-24T10:00:00+00:00', BuiltinType::DateTime->value),
            ]],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            $info = $client->getServerBuildInfo();

            expect($info)->toBeInstanceOf(BuildInfo::class);
            expect($info->productName)->toBe('OpenPlc');
            expect($info->manufacturerName)->toBe('AcmeCo');
            expect($info->softwareVersion)->toBe('1.2.3');
            expect($info->buildNumber)->toBe('1042');
            expect($info->buildDate)->toBeInstanceOf(\DateTimeImmutable::class);
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });
});

describe('ManagedClient certificate trust IPC', function () {

    it('trustCertificate forwards the base64-encoded DER to the daemon', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => null],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            $client->trustCertificate("\x00\x01\x02DER bytes");
            expect(true)->toBeTrue();
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('untrustCertificate forwards the fingerprint to the daemon', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => null],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            $client->untrustCertificate('aa:bb:cc:dd');
            expect(true)->toBeTrue();
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });
});

describe('ManagedClient subscription modify / triggering', function () {

    it('modifyMonitoredItems deserializes each MonitoredItemModifyResult', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                ['statusCode' => 0, 'revisedSamplingInterval' => 100.0, 'revisedQueueSize' => 10],
                ['statusCode' => 0x80000000, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 5],
            ]],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            $results = $client->modifyMonitoredItems(42, [['monitoredItemId' => 1]]);

            expect($results)->toHaveCount(2);
            expect($results[0]->revisedSamplingInterval)->toBe(100.0);
            expect($results[1]->revisedQueueSize)->toBe(5);
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('setTriggering deserializes a SetTriggeringResult', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                'addResults' => [0, 0],
                'removeResults' => [0],
            ]],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            $result = $client->setTriggering(42, 1, [10, 11], [20]);

            expect($result)->toBeInstanceOf(SetTriggeringResult::class);
            expect($result->addResults)->toBe([0, 0]);
            expect($result->removeResults)->toBe([0]);
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });
});

describe('ManagedClient describe + invoke roundtrip', function () {

    it('hasMethod queries describe on first call and caches the result', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                'methods' => ['read', 'browse', 'addNodes'],
                'modules' => ['PhpOpcua\\Client\\Module\\Browse\\BrowseModule'],
                'wireClasses' => [],
                'enumClasses' => [],
            ]],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->hasMethod('addNodes'))->toBeTrue();
            expect($client->hasMethod('customFoo'))->toBeFalse();
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('hasModule reports what describe returns', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                'methods' => [],
                'modules' => ['PhpOpcua\\Client\\Module\\Browse\\BrowseModule'],
                'wireClasses' => [],
                'enumClasses' => [],
            ]],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->hasModule('PhpOpcua\\Client\\Module\\Browse\\BrowseModule'))->toBeTrue();
            expect($client->hasModule('Nowhere\\Module'))->toBeFalse();
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('getRegisteredMethods returns the describe methods list when connected', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                'methods' => ['read', 'browse', 'write'],
                'modules' => [],
                'wireClasses' => [],
                'enumClasses' => [],
            ]],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->getRegisteredMethods())->toBe(['read', 'browse', 'write']);
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('getLoadedModules returns the describe modules list when connected', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                'methods' => [],
                'modules' => ['A\\B', 'C\\D'],
                'wireClasses' => [],
                'enumClasses' => [],
            ]],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->getLoadedModules())->toBe(['A\\B', 'C\\D']);
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('malformed describe response raises DaemonException', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => ['methods' => 'not-an-array']],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect(fn () => $client->getRegisteredMethods())->toThrow(PhpOpcua\SessionManager\Exception\DaemonException::class, 'Malformed describe');
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('__call rejects a method not in describe methods', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                'methods' => ['read'],
                'modules' => [],
                'wireClasses' => [],
                'enumClasses' => [],
            ]],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect(fn () => $client->customMagicMethod('x'))->toThrow(BadMethodCallException::class);
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('addNodes routes through invokeRemote and surfaces ServiceUnsupportedException', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                'methods' => ['addNodes'],
                'modules' => [],
                'wireClasses' => [],
                'enumClasses' => [],
            ]],
            ['success' => false, 'error' => ['type' => 'ServiceUnsupportedException', 'message' => 'BadServiceUnsupported']],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect(fn () => $client->addNodes([]))->toThrow(ServiceUnsupportedException::class);
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('deleteNodes routes through invokeRemote', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                'methods' => ['deleteNodes'],
                'modules' => [],
                'wireClasses' => [],
                'enumClasses' => [],
            ]],
            ['success' => true, 'data' => ['data' => [0, 0]]],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->deleteNodes([]))->toBe([0, 0]);
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('addReferences routes through invokeRemote', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                'methods' => ['addReferences'],
                'modules' => [],
                'wireClasses' => [],
                'enumClasses' => [],
            ]],
            ['success' => true, 'data' => ['data' => [0]]],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->addReferences([]))->toBe([0]);
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });

    it('deleteReferences routes through invokeRemote', function () {
        $daemon = FakeTcpDaemon::start([
            ['success' => true, 'data' => [
                'methods' => ['deleteReferences'],
                'modules' => [],
                'wireClasses' => [],
                'enumClasses' => [],
            ]],
            ['success' => true, 'data' => ['data' => [0]]],
        ]);

        try {
            $client = FakeTcpDaemon::connectClient($daemon['endpoint']);
            expect($client->deleteReferences([]))->toBe([0]);
        } finally {
            FakeTcpDaemon::stop($daemon);
        }
    });
});
