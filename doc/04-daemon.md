# Daemon

The daemon is the central process that keeps OPC UA sessions alive across PHP requests. It runs a ReactPHP event loop, listens on a Unix socket, and manages the lifecycle of all active sessions.

## Starting the Daemon

```bash
php bin/opcua-session-manager
```

Output:
```
OPC UA Session Manager started on /tmp/opcua-session-manager.sock
Timeout: 600s, Cleanup interval: 30s, Max sessions: 100
Socket permissions: 600
```

## CLI Options

| Option | Default | Description |
|--------|---------|-------------|
| `--socket <path>` | `/tmp/opcua-session-manager.sock` | Unix socket path |
| `--timeout <sec>` | `600` | Session inactivity timeout in seconds |
| `--cleanup-interval <sec>` | `30` | Interval between expired session cleanup runs |
| `--auth-token <token>` | *(none)* | Shared secret for IPC authentication |
| `--auth-token-file <path>` | *(none)* | Read auth token from file (recommended) |
| `--max-sessions <n>` | `100` | Maximum concurrent sessions |
| `--socket-mode <octal>` | `0600` | Socket file permissions |
| `--allowed-cert-dirs <dirs>` | *(none)* | Comma-separated allowed certificate directories |
| `--log-file <path>` | stderr | Log file path |
| `--log-level <level>` | `info` | Minimum log level (`debug`, `info`, `warning`, `error`, ...) |
| `--cache-driver <driver>` | `memory` | Cache driver (`memory`, `file`, `none`) |
| `--cache-path <path>` | *(none)* | Cache directory (required for `file` driver) |
| `--cache-ttl <seconds>` | `300` | Cache TTL in seconds |
| `--help`, `-h` | | Show help |

### Auth Token Priority

1. `OPCUA_AUTH_TOKEN` environment variable (highest — not visible in process list)
2. `--auth-token-file` (recommended for production)
3. `--auth-token` (visible in `ps`/`top` — use only for development)

## Security

### IPC Authentication

Optional but recommended. Pass a shared secret via environment variable, file, or CLI argument. Validated with timing-safe `hash_equals()`.

```bash
openssl rand -hex 32 > /etc/opcua/daemon.token
chmod 600 /etc/opcua/daemon.token

OPCUA_AUTH_TOKEN=$(cat /etc/opcua/daemon.token) php bin/opcua-session-manager
```

### Socket Permissions

The socket file is created with `0600` (owner read/write only) by default. For group-shared access:

```bash
php bin/opcua-session-manager --socket-mode 0660
```

### Method Whitelist

Only 37 documented OPC UA operations can be invoked via `query`. Blocked methods include all setters (`setTimeout`, `setSecurityPolicy`, `setUserCredentials`), `connect`, `disconnect`, and PHP magic methods. The `open` and `close` IPC commands handle connection lifecycle exclusively.

### Credential Protection

Passwords and private key paths are stripped from session metadata immediately after the OPC UA connection is established. The `list` command never exposes them.

### Certificate Path Restrictions

```bash
php bin/opcua-session-manager --allowed-cert-dirs /etc/opcua/certs,/var/opcua/certs
```

Certificate file paths must be absolute and point to existing regular files. With `--allowed-cert-dirs`, paths are additionally constrained to the specified directories.

### Connection Limits

- **Max concurrent IPC connections**: 50
- **Per-connection timeout**: 30 seconds (anti-slowloris)
- **Max request size**: 1MB
- **Max sessions**: configurable via `--max-sessions`

### Error Sanitization

Exception messages returned to clients are truncated to 500 characters and have file paths replaced with `[path]`.

## Logging

### CLI Options

| Option | Default | Description |
|--------|---------|-------------|
| `--log-file <path>` | stderr | Log file path. Use `php://stderr` or `php://stdout` for console output. |
| `--log-level <level>` | `info` | Minimum log level: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. |

```bash
php bin/opcua-session-manager --log-file /var/log/opcua-daemon.log --log-level debug
```

### What Gets Logged

| Level | Events |
|-------|--------|
| **INFO** | Daemon startup/shutdown, session created/expired/disconnected, cache configuration |
| **WARNING** | Auth failures, no auth token configured |
| **DEBUG** | OPC UA protocol details (handshake, secure channel, session — from the underlying `Client`) |
| **ERROR** | Connection failures, OPC UA errors (from the underlying `Client`) |

### Logging Architecture: Session Manager vs Direct Client

With the direct `Client` from `opcua-client`, logging is straightforward — you create a logger, pass it to the client, and all log output goes to your application:

```php
$client = ClientBuilder::create()
    ->logger($myLogger)
    ->connect('opc.tcp://localhost:4840');
$client->read('i=2259');
```

```
[2026-03-22 10:00:01] [INFO] Connected to opc.tcp://localhost:4840
[2026-03-22 10:00:01] [DEBUG] Secure channel opened (id: 1)
[2026-03-22 10:00:01] [DEBUG] Session created (id: ns=0;b=...)
```

With the session manager, there are **two separate processes** and **two separate logger instances**:

```
┌───────────────────────────┐         ┌──────────────────────────────────────┐
│  PHP Application          │         │  Daemon Process                      │
│                           │         │                                      │
│  ManagedClient            │  IPC    │  StreamLogger (--log-file)           │
│    └── logger (local)     │ ──────► │    ├── daemon events (startup, etc.) │
│        (NullLogger)       │         │    └── Client logger (OPC UA)        │
│                           │         │        ├── connections               │
│                           │         │        ├── retries                   │
│                           │         │        └── errors                    │
└───────────────────────────┘         └──────────────────────────────────────┘
```

**Key differences:**

| | Direct `Client` | Session Manager |
|-|-----------------|-----------------|
| Who creates the `Client`? | Your application | The daemon (`CommandHandler`) |
| Who configures the logger? | Your application (`new Client(logger: $x)`) | The daemon (`--log-file`, `--log-level`) |
| Where do OPC UA logs go? | Your application's logger | The daemon's log file/stream |
| Can `ManagedClient` see OPC UA logs? | N/A | No — they stay in the daemon process |
| `ManagedClient.setLogger()` | N/A | Local only — does not affect the daemon's `Client` |

**`ManagedClient.setLogger()`** sets a logger on the client-side proxy. It does **not** forward the logger to the daemon. The daemon's `Client` instances always use the logger configured via `--log-file` / `--log-level`. This is by design — a PSR-3 `LoggerInterface` cannot be serialized over IPC.

**In practice:** if you need to see OPC UA protocol logs (connections, retries, handshake details), look at the daemon's log output, not your application's logs. Your application only sees IPC-level errors (`DaemonException`, `ConnectionException`, `ServiceException`) re-thrown by `ManagedClient`.

### Production Example

```bash
php bin/opcua-session-manager \
    --log-file /var/log/opcua-daemon.log \
    --log-level info \
    --socket /var/run/opcua-session-manager.sock
```

Output in `/var/log/opcua-daemon.log`:
```
[2026-03-22 10:00:00] [INFO] OPC UA Session Manager started on /var/run/opcua-session-manager.sock
[2026-03-22 10:00:00] [INFO] Timeout: 600s, Cleanup interval: 30s, Max sessions: 100
[2026-03-22 10:00:00] [INFO] Socket permissions: 660
[2026-03-22 10:00:05] [INFO] Connected to opc.tcp://192.168.1.100:4840
[2026-03-22 10:10:05] [INFO] Session a1b2c3d4... expired (endpoint: opc.tcp://192.168.1.100:4840)
[2026-03-22 10:15:00] [INFO] Shutting down...
[2026-03-22 10:15:00] [INFO] Disconnected session e5f6g7h8...
```

## Cache

### CLI Options

| Option | Default | Description |
|--------|---------|-------------|
| `--cache-driver <driver>` | `memory` | Cache driver: `memory`, `file`, `none`. |
| `--cache-path <path>` | *(none)* | Cache directory (required when `--cache-driver=file`). |
| `--cache-ttl <seconds>` | `300` | Default cache TTL in seconds. |

### Drivers

| Driver | Description |
|--------|-------------|
| `memory` | In-memory cache (`InMemoryCache`). Fast, but lost on daemon restart. Default. |
| `file` | File-based cache (`FileCache`). Survives daemon restarts. Requires `--cache-path`. |
| `none` | Caching disabled. Every operation hits the OPC UA server. |

### What Gets Cached

The cache is configured on the daemon's `Client` instances, not on `ManagedClient`. The following operations are cached by the underlying `opcua-client`:

- `browse()` and `browseAll()` results
- `resolveNodeId()` results
- `getEndpoints()` results
- `discoverDataTypes()` type definitions

### Cache Architecture: Session Manager vs Direct Client

With the direct `Client`, you set the cache on your client instance:

```php
$client = ClientBuilder::create()
    ->cache(new FileCache('/tmp/opcua-cache'))
    ->connect('opc.tcp://localhost:4840');
```

With the session manager, the cache is configured **on the daemon**, not on `ManagedClient`:

```bash
php bin/opcua-session-manager --cache-driver file --cache-path /tmp/opcua-cache --cache-ttl 600
```

**`ManagedClient.setCache()`** stores a cache instance locally to satisfy `OpcUaClientInterface`, but it is **not used** for OPC UA operations. The daemon's `Client` instances use the cache configured via CLI options. `invalidateCache()` and `flushCache()` are forwarded to the daemon and operate on the daemon's cache.

### Production Example

```bash
php bin/opcua-session-manager \
    --cache-driver file \
    --cache-path /var/cache/opcua \
    --cache-ttl 600
```

With `FileCache`, discovered data types and browse results survive daemon restarts — no need to re-query the OPC UA server after a restart.

## Running as a Service

### systemd

```ini
[Unit]
Description=OPC UA Session Manager
After=network.target

[Service]
Type=simple
User=opcua
ExecStart=/usr/bin/php /opt/myapp/vendor/bin/opcua-session-manager \
    --socket /var/run/opcua-session-manager.sock \
    --socket-mode 0660 \
    --auth-token-file /etc/opcua/daemon.token \
    --allowed-cert-dirs /etc/opcua/certs
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Supervisor

```ini
[program:opcua-session-manager]
command=php /opt/myapp/vendor/bin/opcua-session-manager --socket /var/run/opcua-session-manager.sock
user=opcua
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/opcua-session-manager.log
```

## Internals

### Event Loop

The daemon uses ReactPHP's event loop with:

- **Unix socket server** — accepts IPC connections
- **Periodic cleanup timer** — runs every `--cleanup-interval` seconds, disconnects expired sessions
- **Signal handlers** — SIGTERM/SIGINT trigger graceful shutdown

### Request Lifecycle

1. Client connects to the Unix socket
2. Daemon reads a JSON-encoded command (newline-delimited)
3. Auth token validated (if configured)
4. `CommandHandler` dispatches the command (`open`, `close`, `query`, `list`, `ping`)
5. For `query`: method checked against whitelist, parameters deserialized, method invoked on the session's `Client`, result serialized
6. JSON response sent back, connection closed

### Session Lifecycle

- **Created** on `open` command — daemon creates a `Client`, applies configuration, connects to the OPC UA server, generates a 32-character hex session ID
- **Touched** on every `query` — `lastUsed` timestamp updated
- **Expired** when `(now - lastUsed) > timeout` — cleaned up by the periodic timer
- **Closed** on `close` command or daemon shutdown — `Client::disconnect()` called, session removed

### Shutdown Sequence

1. SIGTERM/SIGINT received
2. Auto-publish timers stopped (if active)
3. All active sessions disconnected
4. Unix socket server closed
5. Socket file and PID file removed
6. Event loop stopped

## Auto-Publish

When the daemon is started with a PSR-14 `EventDispatcherInterface` and `autoPublish: true`, it automatically manages the publish cycle for sessions with active subscriptions.

### How It Works

1. A session creates its first subscription via `createSubscription()` → the daemon starts an auto-publish timer for that session
2. The timer calls `Client::publish()`, which dispatches PSR-14 events internally: `DataChangeReceived`, `EventNotificationReceived`, `AlarmActivated`, `SubscriptionKeepAlive`, etc.
3. Acknowledgements are tracked and sent automatically on the next publish call
4. When `moreNotifications` is `true`, the next publish is scheduled with near-zero delay to drain queued notifications quickly
5. When all subscriptions are deleted, the auto-publish timer is stopped

### Timer Scheduling

Auto-publish uses self-rescheduling one-shot timers (not periodic timers) to avoid callback accumulation when `publish()` blocks. The next timer delay depends on the result:

| Scenario | Next delay |
|----------|-----------|
| Notifications received, `moreNotifications: false` | `session.minPublishingInterval × 0.75` |
| Notifications received, `moreNotifications: true` | 10ms (drain quickly) |
| Connection error, recovery succeeded | 1s |
| Generic error (transient) | 5s (backoff) |
| 5 consecutive generic errors | auto-publish stopped |

### Blocking Behavior

`Client::publish()` is a synchronous call that blocks the ReactPHP event loop until the OPC UA server responds with a notification or a keep-alive. The maximum block duration is bounded by `maxKeepAliveCount × publishingInterval` (default: 10 × 500ms = 5s). IPC requests queue during the block but are not lost (30s IPC timeout). To minimize blocking, use a lower `maxKeepAliveCount` (e.g., 3–5).

### Manual Publish Blocking

When auto-publish is active for a session, manual `publish()` calls via IPC return an `auto_publish_active` error. This prevents conflicting publish cycles.

### Programmatic Configuration

```php
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use Psr\EventDispatcher\EventDispatcherInterface;

$daemon = new SessionManagerDaemon(
    socketPath: '/tmp/opcua.sock',
    clientEventDispatcher: $dispatcher,  // PSR-14 dispatcher
    autoPublish: true,
);

$daemon->run();
```

## Auto-Connect

The daemon can auto-connect to pre-configured endpoints and register subscriptions at startup. Combined with auto-publish, this enables fully declarative monitoring with zero application code.

### Programmatic Configuration

```php
$daemon = new SessionManagerDaemon(
    socketPath: '/tmp/opcua.sock',
    clientEventDispatcher: $dispatcher,
    autoPublish: true,
);

$daemon->autoConnect([
    'plc-1' => [
        'endpoint' => 'opc.tcp://192.168.1.10:4840',
        'config' => [
            'username' => 'operator',
            'password' => 'secret',
            'opcuaTimeout' => 3.0,
        ],
        'subscriptions' => [
            [
                'publishing_interval' => 500.0,
                'max_keep_alive_count' => 5,
                'monitored_items' => [
                    ['node_id' => 'ns=2;s=Temperature', 'client_handle' => 1],
                    ['node_id' => 'ns=2;s=Pressure', 'client_handle' => 2],
                ],
                'event_monitored_items' => [
                    [
                        'node_id' => 'i=2253',
                        'client_handle' => 10,
                        'select_fields' => ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
                    ],
                ],
            ],
        ],
    ],
]);

$daemon->run();
```

Connections are established on the first event loop tick after the daemon starts. Failed connections are logged but do not prevent the daemon from starting.
