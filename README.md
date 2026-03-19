# OPC UA PHP Client Session Manager

A daemon-based session manager for [`opcua-php-client`](https://github.com/gianfriaur/opcua-php-client) that keeps OPC UA connections alive across PHP requests.

PHP's request/response lifecycle destroys all state (including network connections) at the end of every request. OPC UA requires a 5-step handshake (TCP → Hello/Ack → OpenSecureChannel → CreateSession → ActivateSession) that takes 50-200ms — repeated on every single HTTP request.

This package solves the problem with a long-running daemon (powered by [ReactPHP](https://reactphp.org/)) that holds OPC UA sessions open in memory. PHP applications communicate with it via a lightweight Unix socket IPC protocol. The connection overhead is paid once; all subsequent requests reuse the existing session through a drop-in `ManagedClient` that implements the same `OpcUaClientInterface` as the direct `Client`.

## The problem

PHP follows a **request/response model**: each HTTP request spawns a process (or worker), executes the script, returns the response, and then the process state is destroyed. There is no built-in way to keep a network connection alive between two separate HTTP requests.

OPC UA, on the other hand, is a **stateful protocol**. Communicating with an OPC UA server requires a multi-step setup:

1. **TCP connection** — open a socket to the server
2. **Hello/Acknowledge** — OPC UA transport handshake to negotiate buffer sizes
3. **OpenSecureChannel** — establish a cryptographic secure channel (even with SecurityPolicy None, this step is mandatory)
4. **CreateSession** — the server allocates a session and returns a session ID and nonce
5. **ActivateSession** — authenticate (anonymous, username/password, or X.509 certificate) and bind the session to the secure channel

Only after all 5 steps can you actually perform operations (read, write, browse, etc.). This setup typically takes **50-200ms** depending on network latency and security configuration.

In a traditional PHP application, this means:

```
Request 1:  [connect 150ms] [read 5ms] [disconnect]  → total ~155ms
Request 2:  [connect 150ms] [read 5ms] [disconnect]  → total ~155ms
Request 3:  [connect 150ms] [read 5ms] [disconnect]  → total ~155ms
```

The connection overhead dominates every request. For a dashboard polling 10 values per second, you'd spend **1.5 seconds per second** just connecting and disconnecting — making it completely impractical.

Frameworks like Symfony or Laravel don't help here: even with long-lived PHP-FPM workers, each request starts with a fresh script execution. There's no shared state between requests to hold a socket open.

### What about persistent connections?

PHP has `pconnect` for databases (MySQL, PostgreSQL), but there is no equivalent for OPC UA. The OPC UA protocol is too complex for a simple persistent connection: sessions have server-side state (subscriptions, monitored items, continuation points) that must be actively maintained with periodic keep-alive messages. A passive "leave the socket open" approach doesn't work — the server will close the session after its timeout.

### The real cost

Beyond raw latency, reconnecting on every request means:
- **No subscriptions** — you can't subscribe to value changes if the session dies after each request
- **No continuation points** — browse results with pagination are lost between requests
- **Server load** — creating/destroying sessions puts unnecessary load on the OPC UA server
- **Certificate handshake** — with security enabled, the TLS-like handshake adds even more overhead

## The solution

A long-running daemon (powered by [ReactPHP](https://reactphp.org/)) holds OPC UA sessions open in memory. PHP applications communicate with it via a lightweight Unix socket IPC protocol. Sessions are automatically cleaned up after a configurable inactivity timeout.

```
┌──────────────┐         ┌────────────────────────────┐         ┌──────────────┐
│  PHP Request │ ──IPC──►│  Session Manager Daemon     │ ──TCP──►│  OPC UA      │
│  (short-     │◄──IPC── │                              │◄──TCP── │  Server      │
│   lived)     │         │  ● ReactPHP event loop       │         │              │
└──────────────┘         │  ● Sessions stored in memory │         └──────────────┘
                         │  ● Periodic cleanup timer    │
┌──────────────┐         │  ● Signal handlers (SIGTERM) │
│  PHP Request │ ──IPC──►│                              │
│  (reuses     │◄──IPC── │  Sessions:                   │
│   session)   │         │   [sess-a1b2] → Client (TCP) │
└──────────────┘         │   [sess-c3d4] → Client (TCP) │
                         └────────────────────────────┘
```

### How it works

1. **First request**: the PHP application sends an `open` command to the daemon via the Unix socket. The daemon creates a real OPC UA `Client`, performs the full 5-step handshake, and returns a session ID.
2. **Subsequent requests**: the PHP application sends `query` commands referencing the session ID. The daemon looks up the existing `Client` (already connected) and executes the operation directly — no handshake needed.
3. **Between requests**: the daemon keeps the TCP connection alive. The ReactPHP event loop runs a periodic timer that updates the session's `lastUsed` timestamp on every operation and closes sessions that exceed the inactivity timeout.
4. **Cleanup**: expired sessions are automatically disconnected and removed. On SIGTERM/SIGINT, the daemon gracefully disconnects all active sessions before exiting.

With the session manager, the same dashboard scenario becomes:

```
Request 1:  [open session 150ms] [read 5ms]           → total ~155ms  (first time only)
Request 2:                        [read 5ms]           → total ~5ms
Request 3:                        [read 5ms]           → total ~5ms
...
Request N:                        [read 5ms]           → total ~5ms
```

The IPC overhead (Unix socket JSON roundtrip) adds ~5-10ms per operation compared to a direct `Client` call, but this is negligible compared to the 50-200ms saved by not reconnecting.

### ManagedClient — drop-in replacement

`ManagedClient` implements the same `OpcUaClientInterface` as the direct `Client` from [`opcua-php-client`](https://github.com/gianfriaur/opcua-php-client). This means you can swap one for the other without changing your application code:

```php
// Before: direct client (session dies with the PHP process)
$client = new Client();
$client->connect('opc.tcp://localhost:4840');
$value = $client->read(NodeId::numeric(0, 2259));

// After: managed client (session persists across requests)
$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');
$value = $client->read(NodeId::numeric(0, 2259));
```

Under the hood, `ManagedClient` translates every method call into a JSON message sent to the daemon over the Unix socket. The daemon deserializes the parameters, calls the corresponding method on the real `Client`, serializes the result, and sends it back. Type conversion is handled transparently by `TypeSerializer` — `NodeId`, `DataValue`, `Variant`, `QualifiedName`, `LocalizedText`, `ReferenceDescription`, and all scalar/array types are supported.

### IPC protocol

Communication uses a simple JSON-over-Unix-socket protocol:

- **Transport**: Unix domain socket, JSON + `\n` as delimiter
- **Model**: request/response — one command per connection
- **Commands**: `ping`, `list`, `open`, `close`, `query`
- **Authentication**: optional shared-secret token (timing-safe `hash_equals`)
- **Limits**: 1MB max request size, 30s connection timeout, 50 max concurrent connections

See [IPC Protocol](doc/ipc-protocol.md) for the full specification with examples.

## Requirements

- PHP >= 8.2
- `ext-openssl`
- `ext-pcntl` (recommended)
- [`gianfriaur/opcua-php-client`](https://github.com/gianfriaur/opcua-php-client)

## Installation

```bash
composer require gianfriaur/opcua-php-client-session-manager
```

## Quick start

### 1. Start the daemon

```bash
php bin/opcua-session-manager
```

### 2. Use ManagedClient in your PHP code

`ManagedClient` is a drop-in replacement for `Client` — it implements the same `OpcUaClientInterface`:

```php
use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840/UA/Server');

// Read a value
$dataValue = $client->read(NodeId::numeric(0, 2259));
echo "Server State: " . $dataValue->getValue() . "\n";

// Browse the address space
$refs = $client->browse(NodeId::numeric(0, 85));
foreach ($refs as $ref) {
    echo $ref->getBrowseName()->getName() . "\n";
}

$client->disconnect();
```

### 3. Persist sessions across PHP requests

The main advantage — the OPC UA session survives between HTTP requests:

```php
// Request 1: open and save session ID
$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840/UA/Server');
$_SESSION['opcua'] = $client->getSessionId();
// Do NOT disconnect — session stays alive in daemon

// Request 2: reuse the session (no handshake overhead)
$response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
    'command' => 'query',
    'sessionId' => $_SESSION['opcua'],
    'method' => 'read',
    'params' => [['ns' => 0, 'id' => 2259, 'type' => 'numeric'], 13],
]);
```

## Daemon options

```bash
php bin/opcua-session-manager [options]
```

| Option | Default | Description |
|--------|---------|-------------|
| `--socket <path>` | `/tmp/opcua-session-manager.sock` | Unix socket path |
| `--timeout <sec>` | `600` | Session inactivity timeout |
| `--cleanup-interval <sec>` | `30` | Expired session cleanup interval |
| `--auth-token <token>` | *(none)* | Shared secret for IPC authentication |
| `--auth-token-file <path>` | *(none)* | Read auth token from file |
| `--max-sessions <n>` | `100` | Maximum concurrent sessions |
| `--socket-mode <octal>` | `0600` | Socket file permissions |
| `--allowed-cert-dirs <dirs>` | *(none)* | Comma-separated allowed certificate directories |

## Security

The daemon implements multiple layers of security hardening:

- **IPC authentication** — optional shared-secret token via `OPCUA_AUTH_TOKEN` env var, `--auth-token-file`, or `--auth-token`, validated with timing-safe `hash_equals()`. Recommended for production
- **Socket permissions** — file created with `0600` by default (owner-only). Adjustable via `--socket-mode`
- **Method whitelist** — only 32 documented OPC UA read/write/browse/state methods can be invoked via `query`. `connect`/`disconnect` are restricted to `open`/`close` commands only
- **Credential protection** — passwords and private key paths are stripped from session data immediately after connection. The `list` command never exposes them
- **Session limits** — configurable maximum (`--max-sessions`) to prevent resource exhaustion
- **Certificate path restrictions** — `--allowed-cert-dirs` constrains which directories the daemon reads certificates from. Without it, paths are still validated as existing regular files
- **Input size limit** — IPC requests are capped at 1MB to prevent memory exhaustion
- **Connection protection** — 30s per-connection timeout (anti-slowloris), max 50 concurrent IPC connections
- **Error sanitization** — exception messages are truncated and file paths stripped to prevent information leakage
- **PID file lock** — prevents multiple daemon instances from running on the same socket

### Recommended production setup

```bash
# Generate auth token
openssl rand -hex 32 > /etc/opcua/daemon.token
chmod 600 /etc/opcua/daemon.token

# Start daemon (token via env var — not visible in process list)
OPCUA_AUTH_TOKEN=$(cat /etc/opcua/daemon.token) php bin/opcua-session-manager \
    --socket /var/run/opcua-session-manager.sock \
    --socket-mode 0660 \
    --max-sessions 50 \
    --allowed-cert-dirs /etc/opcua/certs
```

```php
// In your PHP application
$client = new ManagedClient(
    socketPath: '/var/run/opcua-session-manager.sock',
    authToken: trim(file_get_contents('/etc/opcua/daemon.token')),
);
```

## Features

- **All OPC UA operations**: browse, browseAll, browseRecursive, read, write, method calls, subscriptions, history read
- **Path resolution**: `resolveNodeId('/Objects/Server/ServerStatus')` and `translateBrowsePaths()`
- **Connection management**: `isConnected()`, `getConnectionState()`, `reconnect()`, configurable timeout and auto-retry
- **Automatic batching**: transparent batching for `readMulti()`/`writeMulti()` with server limits auto-discovery
- **Security**: Basic256Sha256, SignAndEncrypt, username/password, X.509 certificates
- **IPC hardening**: auth token, method whitelist (32 methods), input limits, credential protection
- **Session persistence**: sessions survive across PHP requests
- **Automatic cleanup**: expired sessions are closed after inactivity timeout
- **Graceful shutdown**: SIGTERM/SIGINT disconnect all sessions cleanly
- **Drop-in replacement**: `ManagedClient` implements the same `OpcUaClientInterface` as `Client`
- **Error mapping**: daemon errors are re-thrown as the original `opcua-php-client` exception types

## Comparison

| | Direct `Client` | `ManagedClient` |
|-|-----------------|-----------------|
| Connection | Direct TCP | Via daemon (Unix socket) |
| Session lifetime | Dies with PHP process | Persists across requests |
| Per-operation overhead | ~1-5ms | ~5-15ms |
| Connection overhead | ~50-200ms every request | ~50-200ms first time only |
| Certificate paths | Relative or absolute | Absolute only |

## Documentation

- [Overview & Architecture](doc/overview.md)
- [Installation](doc/installation.md)
- [Daemon](doc/daemon.md)
- [ManagedClient API](doc/managed-client.md)
- [IPC Protocol](doc/ipc-protocol.md)
- [Type Serialization](doc/type-serialization.md)
- [Testing](doc/testing.md)
- [Examples](doc/examples.md)

## Testing

```bash
# Unit tests (no external dependencies)
vendor/bin/pest tests/Unit

# Integration tests (requires Docker OPC UA test servers)
vendor/bin/pest tests/Integration --group=integration

# All tests
vendor/bin/pest
```

200 tests (94 unit + 106 integration) covering:

- **Connection**: anonymous, username/password, certificate, reconnect, invalid host/port, connection state, timeout, auto-retry
- **Browse**: Objects, TestServer, DataTypes, Methods, inverse, continuation, browseAll, browseRecursive, BrowseDirection enum
- **Path resolution**: resolveNodeId, translateBrowsePaths
- **Read/Write**: all scalar types, arrays, readMulti, writeMulti, read-only rejection, batching
- **Method calls**: Add, Multiply, Concatenate, Reverse, Echo, Failing
- **Subscriptions**: create, monitored items, publish, delete
- **Session persistence**: cross-instance, state persistence, isolation
- **Type serialization**: all OPC UA types, BrowseDirection, ConnectionState, BrowseNode, roundtrips, edge cases
- **Configuration**: timeout, auto-retry, batching, browse depth
- **Security**: method whitelist (including setter rejection), connect/disconnect rejection, credential stripping, error sanitization, auth token (accept/reject/wrong), buffer overflow, socket permissions, max sessions, certificate path validation

## Ecosystem

This package is part of a broader OPC UA ecosystem for PHP:

| Package | Description |
|---------|-------------|
| [opcua-php-client](https://github.com/GianfriAur/opcua-php-client) | Pure PHP OPC UA client library — the core protocol implementation |
| [opcua-php-client-session-manager](https://github.com/GianfriAur/opcua-php-client-session-manager) | Session persistence daemon for PHP's request/response model (this package) |
| [opcua-laravel-client](https://github.com/GianfriAur/opcua-laravel-client) | Laravel integration for OPC UA — service provider, facade, and configuration |
| [opcua-test-server-suite](https://github.com/GianfriAur/opcua-test-server-suite) | Docker-based OPC UA test server suite for integration testing |

## License

MIT
