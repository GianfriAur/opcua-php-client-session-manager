# ManagedClient

`ManagedClient` is the proxy client that PHP applications use instead of the direct `Client`. It implements `OpcUaClientInterface`, making it a drop-in replacement.

## Basic usage

```php
use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

// Create the client (points to the daemon)
$client = new ManagedClient('/tmp/opcua-session-manager.sock');

// Connect (the daemon opens the OPC UA session)
$client->connect('opc.tcp://my-server:4840/UA/Server');

// Use it like a regular Client
$refs = $client->browse(NodeId::numeric(0, 85));
$value = $client->read(NodeId::numeric(2, 1001));

// Disconnect (the daemon closes the OPC UA session)
$client->disconnect();
```

## Constructor

```php
new ManagedClient(
    string $socketPath = '/tmp/opcua-session-manager.sock',
    float $timeout = 30.0,
    ?string $authToken = null,
)
```

| Parameter | Default | Description |
|-----------|---------|-------------|
| `$socketPath` | `/tmp/opcua-session-manager.sock` | Path to the daemon's Unix socket |
| `$timeout` | `30.0` | Timeout in seconds for each IPC request |
| `$authToken` | `null` | Shared secret for daemon authentication (must match daemon's `--auth-token`) |

When `$authToken` is provided, it is automatically included in every IPC request. The daemon validates it using a timing-safe comparison (`hash_equals`).

## Configuration methods

### Timeout

```php
$client->setTimeout(10.0); // OPC UA operation timeout (default: 5s)
$client->getTimeout();     // Returns 10.0
```

The timeout is passed to the daemon's `Client` on `connect()` and controls OPC UA operation timeouts, not the IPC timeout.

### Auto-retry

```php
$client->setAutoRetry(3);  // Max retries on ConnectionException
$client->getAutoRetry();   // Returns 3
```

When `autoRetry` is not explicitly set, the default is `0` before `connect()` and `1` after a successful `connect()`. The retry mechanism operates inside the daemon — transparent to the PHP application.

### Batching

```php
$client->setBatchSize(100);           // Max nodes per batch
$client->getBatchSize();              // Returns 100
$client->getServerMaxNodesPerRead();  // Auto-discovered from server
$client->getServerMaxNodesPerWrite(); // Auto-discovered from server
```

`readMulti()` and `writeMulti()` are automatically batched when the number of items exceeds the effective batch size. `setBatchSize(0)` disables batching entirely.

### Browse depth

```php
$client->setDefaultBrowseMaxDepth(20); // Default max depth for browseRecursive
$client->getDefaultBrowseMaxDepth();   // Returns 20
```

Default is `10`. Set to `-1` for unlimited (hardcapped at 256 by the underlying client).

## Security configuration

Configuration methods are identical to `Client`:

```php
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;

$client = new ManagedClient();

// Security policy and mode
$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);

// Client certificate (for the secure channel)
$client->setClientCertificate(
    '/path/to/client/cert.pem',
    '/path/to/client/key.pem',
    '/path/to/ca/cert.pem',  // optional
);

// Username/password authentication
$client->setUserCredentials('admin', 'admin123');

// User certificate authentication
$client->setUserCertificate(
    '/path/to/user/cert.pem',
    '/path/to/user/key.pem',
);

$client->connect('opc.tcp://secure-server:4840/UA/Server');
```

> **Important**: Certificate paths must be **absolute**. The daemon resolves them from its own working directory, not from the PHP application's directory.

## Operational methods

All `OpcUaClientInterface` methods are supported:

### Connection

| Method | Description |
|--------|-------------|
| `connect(string $endpointUrl): void` | Opens an OPC UA session via the daemon |
| `reconnect(): void` | Reconnects the session's underlying client |
| `disconnect(): void` | Closes the session |
| `isConnected(): bool` | Checks if the session is connected |
| `getConnectionState(): ConnectionState` | Returns `Disconnected`, `Connected`, or `Broken` |

### Browse

| Method | Description |
|--------|-------------|
| `browse(NodeId, BrowseDirection, ...)` | Browse a node's children |
| `browseWithContinuation(NodeId, BrowseDirection, ...)` | Browse with continuation point |
| `browseNext(string $continuationPoint)` | Continue a previous browse |
| `browseAll(NodeId, BrowseDirection, ...)` | Browse all children, automatically following continuation points |
| `browseRecursive(NodeId, BrowseDirection, ?int $maxDepth, ...)` | Full tree traversal with cycle detection, returns `BrowseNode[]` |

### Path resolution

| Method | Description |
|--------|-------------|
| `translateBrowsePaths(array $browsePaths)` | Translate browse paths to NodeIds |
| `resolveNodeId(string $path, ?NodeId $startingNodeId)` | Resolve a human-readable path like `/Objects/Server/ServerStatus` |

### Read/Write

| Method | Description |
|--------|-------------|
| `read(NodeId, int $attributeId = 13)` | Read an attribute |
| `readMulti(array $items)` | Read multiple attributes (automatically batched) |
| `write(NodeId, mixed $value, BuiltinType $type)` | Write a value |
| `writeMulti(array $items)` | Write multiple values (automatically batched) |

### Method Call

| Method | Description |
|--------|-------------|
| `call(NodeId $objectId, NodeId $methodId, Variant[] $args)` | Call an OPC UA method |

### Subscription

| Method | Description |
|--------|-------------|
| `createSubscription(...)` | Create a subscription |
| `createMonitoredItems(int $subId, array $items)` | Add monitored items |
| `createEventMonitoredItem(...)` | Add an event monitored item |
| `deleteMonitoredItems(int $subId, int[] $ids)` | Remove monitored items |
| `deleteSubscription(int $subId)` | Delete a subscription |
| `publish(array $acks)` | Execute a publish request |

### History Read

| Method | Description |
|--------|-------------|
| `historyReadRaw(...)` | Raw historical read |
| `historyReadProcessed(...)` | Historical read with aggregation |
| `historyReadAtTime(...)` | Historical read at specific timestamps |

### Endpoints

| Method | Description |
|--------|-------------|
| `getEndpoints(string $endpointUrl)` | List server endpoints |

## Additional methods

```php
// Get the daemon session ID
$sessionId = $client->getSessionId(); // ?string
```

## Error handling

`ManagedClient` re-throws daemon exceptions by mapping them to the original `opcua-php-client` types:

| Daemon error | Client exception |
|--------------|-----------------|
| `ConnectionException` | `Gianfriaur\OpcuaPhpClient\Exception\ConnectionException` |
| `ServiceException` | `Gianfriaur\OpcuaPhpClient\Exception\ServiceException` |
| `session_not_found` | `ConnectionException` (session expired or not found) |
| Any other | `Gianfriaur\OpcuaSessionManager\Exception\DaemonException` |

```php
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaSessionManager\Exception\DaemonException;

try {
    $client->connect('opc.tcp://server:4840');
} catch (ConnectionException $e) {
    // OPC UA connection error (unreachable host, auth failure, etc.)
} catch (DaemonException $e) {
    // Daemon unreachable or generic error
}
```

## Differences from direct Client

| Aspect | `Client` | `ManagedClient` |
|--------|----------|-----------------|
| OPC UA connection | Direct (TCP) | Via daemon (Unix socket IPC) |
| Session persistence | Dies with the PHP process | Survives across requests |
| Per-operation overhead | ~1-5ms | ~5-15ms (IPC + serialization) |
| Connection overhead | ~50-200ms (every request) | ~50-200ms (first time only) |
| Subscription publish | Immediate notifications | Limited by synchronous IPC model |
| Certificate paths | Relative or absolute | Absolute only (resolved by daemon) |
| Auto-retry | Local in-process | Remote in daemon's `Client` |
| Batching | Local in-process | Remote in daemon's `Client` |

## Session persistence across requests

The main advantage of `ManagedClient` is that the OPC UA session persists across PHP requests. To leverage this:

```php
// Request 1: open the session and save its ID
$client = new ManagedClient();
$client->connect('opc.tcp://server:4840');
$sessionId = $client->getSessionId();
// Save $sessionId in PHP session, cache, database, etc.
$_SESSION['opcua_session'] = $sessionId;
// Do NOT call disconnect() — the session stays alive in the daemon

// Request 2: the OPC UA session is already open in the daemon
// You can use SocketConnection directly if you have the sessionId
use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;

$response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
    'command' => 'query',
    'sessionId' => $_SESSION['opcua_session'],
    'method' => 'read',
    'params' => [
        ['ns' => 0, 'id' => 2259, 'type' => 'numeric'],
        13,
    ],
]);

if ($response['success']) {
    $value = $response['data']['value'];
}
```
