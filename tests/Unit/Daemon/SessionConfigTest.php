<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Daemon\SessionConfig;

describe('SessionConfig: fromArray', function () {

    it('constructs an empty config from an empty array', function () {
        $c = SessionConfig::fromArray([]);

        expect($c->opcuaTimeout)->toBeNull();
        expect($c->autoRetry)->toBeNull();
        expect($c->securityPolicy)->toBeNull();
        expect($c->password)->toBeNull();
    });

    it('coerces scalar types from JSON-decoded mixed values', function () {
        $c = SessionConfig::fromArray([
            'opcuaTimeout' => '2.5',
            'autoRetry' => '3',
            'batchSize' => 100,
            'defaultBrowseMaxDepth' => '12',
            'securityMode' => '3',
            'autoAccept' => 1,
            'autoDetectWriteType' => '',
            'readMetadataCache' => true,
        ]);

        expect($c->opcuaTimeout)->toBe(2.5);
        expect($c->autoRetry)->toBe(3);
        expect($c->batchSize)->toBe(100);
        expect($c->defaultBrowseMaxDepth)->toBe(12);
        expect($c->securityMode)->toBe(3);
        expect($c->autoAccept)->toBeTrue();
        expect($c->autoDetectWriteType)->toBeFalse();
        expect($c->readMetadataCache)->toBeTrue();
    });

    it('preserves string paths and URIs', function () {
        $c = SessionConfig::fromArray([
            'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
            'username' => 'admin',
            'password' => 's3cret',
            'clientCertPath' => '/etc/opcua/client.crt',
            'clientKeyPath' => '/etc/opcua/client.key',
            'trustStorePath' => '/etc/opcua/trust',
            'trustPolicy' => 'fingerprint',
        ]);

        expect($c->securityPolicy)->toBe('http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256');
        expect($c->username)->toBe('admin');
        expect($c->password)->toBe('s3cret');
        expect($c->clientCertPath)->toBe('/etc/opcua/client.crt');
        expect($c->clientKeyPath)->toBe('/etc/opcua/client.key');
        expect($c->trustStorePath)->toBe('/etc/opcua/trust');
        expect($c->trustPolicy)->toBe('fingerprint');
    });

    it('silently ignores unknown keys (forwards-compatible)', function () {
        $c = SessionConfig::fromArray(['futureFlag' => true, 'autoRetry' => 2]);

        expect($c->autoRetry)->toBe(2);
    });
});

describe('SessionConfig: toArray', function () {

    it('emits only the non-null fields', function () {
        $c = new SessionConfig(autoRetry: 3, username: 'u', password: 'p');

        $array = $c->toArray();

        expect($array)->toBe([
            'autoRetry' => 3,
            'username' => 'u',
            'password' => 'p',
        ]);
    });

    it('emits an empty array when no field is set', function () {
        expect((new SessionConfig())->toArray())->toBe([]);
    });

    it('round-trips through fromArray -> toArray losslessly for scalar fields', function () {
        $input = [
            'opcuaTimeout' => 1.5,
            'autoRetry' => 2,
            'batchSize' => 50,
            'securityPolicy' => 'http://example/policy',
            'username' => 'u',
            'password' => 'p',
            'autoAccept' => true,
        ];

        $c = SessionConfig::fromArray($input);

        expect($c->toArray())->toBe($input);
    });
});

describe('SessionConfig: sanitized', function () {

    it('nulls out sensitive fields and keeps the rest', function () {
        $c = new SessionConfig(
            opcuaTimeout: 5.0,
            autoRetry: 3,
            username: 'admin',
            password: 's3cret',
            clientCertPath: '/etc/cert.pem',
            clientKeyPath: '/etc/key.pem',
            caCertPath: '/etc/ca.pem',
            userKeyPath: '/etc/user.key',
            trustStorePath: '/etc/trust',
        );

        $sanitized = $c->sanitized();

        expect($sanitized->opcuaTimeout)->toBe(5.0);
        expect($sanitized->autoRetry)->toBe(3);
        expect($sanitized->username)->toBe('admin');
        expect($sanitized->password)->toBeNull();
        expect($sanitized->clientCertPath)->toBe('/etc/cert.pem');
        expect($sanitized->clientKeyPath)->toBeNull();
        expect($sanitized->caCertPath)->toBeNull();
        expect($sanitized->userKeyPath)->toBeNull();
        expect($sanitized->trustStorePath)->toBe('/etc/trust');
    });

    it('sanitized().toArray() omits the sensitive keys entirely', function () {
        $c = new SessionConfig(
            username: 'u',
            password: 'p',
            clientCertPath: '/cert',
            clientKeyPath: '/key',
            caCertPath: '/ca',
            userKeyPath: '/uk',
        );

        $array = $c->sanitized()->toArray();

        expect($array)->toHaveKey('username');
        expect($array)->toHaveKey('clientCertPath');
        expect($array)->not->toHaveKey('password');
        expect($array)->not->toHaveKey('clientKeyPath');
        expect($array)->not->toHaveKey('caCertPath');
        expect($array)->not->toHaveKey('userKeyPath');
    });

    it('SENSITIVE_FIELDS lists every sanitized field', function () {
        expect(SessionConfig::SENSITIVE_FIELDS)->toContain('password');
        expect(SessionConfig::SENSITIVE_FIELDS)->toContain('clientKeyPath');
        expect(SessionConfig::SENSITIVE_FIELDS)->toContain('userKeyPath');
        expect(SessionConfig::SENSITIVE_FIELDS)->toContain('caCertPath');
    });
});
