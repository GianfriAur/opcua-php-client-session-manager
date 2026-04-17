<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Serialization;

use DateTimeImmutable;
use InvalidArgumentException;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\NodeClass;

/**
 * Param deserializer for the method surface shipped with `opcua-session-manager`.
 *
 * Encapsulates the per-method conversion rules previously embedded in
 * `CommandHandler::deserializeParams()` — one arm of a `match` per whitelisted
 * method.
 */
class BuiltInParamDeserializer implements ParamDeserializerInterface
{
    /** @var string[] */
    private const METHODS = [
        'getEndpoints',
        'browse',
        'browseAll',
        'browseWithContinuation',
        'browseRecursive',
        'browseNext',
        'translateBrowsePaths',
        'resolveNodeId',
        'read',
        'readMulti',
        'write',
        'writeMulti',
        'call',
        'createSubscription',
        'createMonitoredItems',
        'createEventMonitoredItem',
        'deleteMonitoredItems',
        'deleteSubscription',
        'publish',
        'transferSubscriptions',
        'republish',
        'historyReadRaw',
        'historyReadProcessed',
        'historyReadAtTime',
        'discoverDataTypes',
        'invalidateCache',
        'modifyMonitoredItems',
        'setTriggering',
        'trustCertificate',
        'untrustCertificate',
        'isConnected',
        'getConnectionState',
        'reconnect',
        'getTimeout',
        'getAutoRetry',
        'getBatchSize',
        'getDefaultBrowseMaxDepth',
        'getServerMaxNodesPerRead',
        'getServerMaxNodesPerWrite',
        'flushCache',
        'getTrustStore',
        'getTrustPolicy',
        'getEventDispatcher',
        'getLogger',
    ];

    /**
     * @param TypeSerializer $serializer
     */
    public function __construct(private readonly TypeSerializer $serializer)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function supports(string $method): bool
    {
        return in_array($method, self::METHODS, true);
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException If `$method` is not supported by this deserializer.
     */
    public function deserialize(string $method, array $params): array
    {
        return match ($method) {
            'getEndpoints' => [
                (string)$params[0],
                (bool)($params[1] ?? true),
            ],
            'browse', 'browseAll' => [
                $this->serializer->deserializeNodeId($params[0]),
                BrowseDirection::from((int)($params[1] ?? 0)),
                isset($params[2]) ? $this->serializer->deserializeNodeId($params[2]) : null,
                (bool)($params[3] ?? true),
                $this->deserializeNodeClasses($params[4] ?? []),
                (bool)($params[5] ?? true),
            ],
            'browseWithContinuation' => [
                $this->serializer->deserializeNodeId($params[0]),
                BrowseDirection::from((int)($params[1] ?? 0)),
                isset($params[2]) ? $this->serializer->deserializeNodeId($params[2]) : null,
                (bool)($params[3] ?? true),
                $this->deserializeNodeClasses($params[4] ?? []),
            ],
            'browseRecursive' => [
                $this->serializer->deserializeNodeId($params[0]),
                BrowseDirection::from((int)($params[1] ?? 0)),
                isset($params[2]) ? (int)$params[2] : null,
                isset($params[3]) ? $this->serializer->deserializeNodeId($params[3]) : null,
                (bool)($params[4] ?? true),
                $this->deserializeNodeClasses($params[5] ?? []),
            ],
            'browseNext' => [
                (string)$params[0],
            ],
            'translateBrowsePaths' => [
                array_map(fn(array $bp) => [
                    'startingNodeId' => $this->serializer->deserializeNodeId($bp['startingNodeId']),
                    'relativePath' => array_map(fn(array $elem) => [
                        'referenceTypeId' => isset($elem['referenceTypeId'])
                            ? $this->serializer->deserializeNodeId($elem['referenceTypeId'])
                            : null,
                        'isInverse' => (bool)($elem['isInverse'] ?? false),
                        'includeSubtypes' => (bool)($elem['includeSubtypes'] ?? true),
                        'targetName' => $this->serializer->deserializeQualifiedName($elem['targetName']),
                    ], $bp['relativePath'] ?? []),
                ], $params[0] ?? []),
            ],
            'resolveNodeId' => [
                (string)$params[0],
                isset($params[1]) ? $this->serializer->deserializeNodeId($params[1]) : null,
                (bool)($params[2] ?? true),
            ],
            'read' => [
                $this->serializer->deserializeNodeId($params[0]),
                (int)($params[1] ?? 13),
                (bool)($params[2] ?? false),
            ],
            'readMulti' => [
                array_map(fn(array $item) => [
                    'nodeId' => $this->serializer->deserializeNodeId($item['nodeId']),
                    'attributeId' => $item['attributeId'] ?? 13,
                ], $params[0]),
            ],
            'write' => [
                $this->serializer->deserializeNodeId($params[0]),
                $params[1],
                isset($params[2]) ? $this->serializer->deserializeBuiltinType((int)$params[2]) : null,
            ],
            'writeMulti' => [
                array_map(fn(array $item) => [
                    'nodeId' => $this->serializer->deserializeNodeId($item['nodeId']),
                    'value' => $item['value'],
                    'type' => isset($item['type']) ? $this->serializer->deserializeBuiltinType((int)$item['type']) : null,
                    'attributeId' => $item['attributeId'] ?? 13,
                ], $params[0]),
            ],
            'call' => [
                $this->serializer->deserializeNodeId($params[0]),
                $this->serializer->deserializeNodeId($params[1]),
                array_map(fn(array $v) => $this->serializer->deserializeVariant($v), $params[2] ?? []),
            ],
            'createSubscription' => [
                (float)($params[0] ?? 500.0),
                (int)($params[1] ?? 2400),
                (int)($params[2] ?? 10),
                (int)($params[3] ?? 0),
                (bool)($params[4] ?? true),
                (int)($params[5] ?? 0),
            ],
            'createMonitoredItems' => [
                (int)$params[0],
                array_map(fn(array $item) => [
                    'nodeId' => $this->serializer->deserializeNodeId($item['nodeId']),
                    'attributeId' => $item['attributeId'] ?? 13,
                    'samplingInterval' => $item['samplingInterval'] ?? 250.0,
                    'queueSize' => $item['queueSize'] ?? 1,
                    'clientHandle' => $item['clientHandle'] ?? 0,
                    'monitoringMode' => $item['monitoringMode'] ?? 0,
                ], $params[1]),
            ],
            'createEventMonitoredItem' => [
                (int)$params[0],
                $this->serializer->deserializeNodeId($params[1]),
                $params[2] ?? ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
                (int)($params[3] ?? 1),
            ],
            'deleteMonitoredItems' => [
                (int)$params[0],
                array_map('intval', $params[1]),
            ],
            'deleteSubscription' => [
                (int)$params[0],
            ],
            'publish' => [
                $params[0] ?? [],
            ],
            'transferSubscriptions' => [
                array_map('intval', $params[0] ?? []),
                (bool)($params[1] ?? false),
            ],
            'republish' => [
                (int)$params[0],
                (int)$params[1],
            ],
            'historyReadRaw' => [
                $this->serializer->deserializeNodeId($params[0]),
                isset($params[1]) ? new DateTimeImmutable($params[1]) : null,
                isset($params[2]) ? new DateTimeImmutable($params[2]) : null,
                (int)($params[3] ?? 0),
                (bool)($params[4] ?? false),
            ],
            'historyReadProcessed' => [
                $this->serializer->deserializeNodeId($params[0]),
                new DateTimeImmutable($params[1]),
                new DateTimeImmutable($params[2]),
                (float)$params[3],
                $this->serializer->deserializeNodeId($params[4]),
            ],
            'historyReadAtTime' => [
                $this->serializer->deserializeNodeId($params[0]),
                array_map(fn(string $ts) => new DateTimeImmutable($ts), $params[1]),
            ],
            'discoverDataTypes' => [
                isset($params[0]) ? (int)$params[0] : null,
                (bool)($params[1] ?? true),
            ],
            'invalidateCache' => [
                $this->serializer->deserializeNodeId($params[0]),
            ],
            'modifyMonitoredItems' => [
                (int)$params[0],
                array_map(fn(array $item) => [
                    'monitoredItemId' => (int)$item['monitoredItemId'],
                    'samplingInterval' => isset($item['samplingInterval']) ? (float)$item['samplingInterval'] : null,
                    'queueSize' => isset($item['queueSize']) ? (int)$item['queueSize'] : null,
                    'clientHandle' => isset($item['clientHandle']) ? (int)$item['clientHandle'] : null,
                    'discardOldest' => isset($item['discardOldest']) ? (bool)$item['discardOldest'] : null,
                ], $params[1]),
            ],
            'setTriggering' => [
                (int)$params[0],
                (int)$params[1],
                array_map('intval', $params[2] ?? []),
                array_map('intval', $params[3] ?? []),
            ],
            'trustCertificate' => [
                (string)$params[0],
            ],
            'untrustCertificate' => [
                (string)$params[0],
            ],
            'isConnected', 'getConnectionState', 'reconnect',
            'getTimeout', 'getAutoRetry', 'getBatchSize',
            'getDefaultBrowseMaxDepth', 'getServerMaxNodesPerRead',
            'getServerMaxNodesPerWrite', 'flushCache',
            'getTrustStore', 'getTrustPolicy', 'getEventDispatcher', 'getLogger' => [],
            default => throw new InvalidArgumentException("Unsupported method: {$method}"),
        };
    }

    /**
     * @param array<int, int> $values Raw OPC UA NodeClass integers.
     * @return NodeClass[]
     */
    private function deserializeNodeClasses(array $values): array
    {
        return array_map(fn(int $v) => NodeClass::from($v), $values);
    }
}
