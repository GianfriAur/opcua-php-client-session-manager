# Changelog

## [2.0.0] - 2026-03-20

### Changed

- **Breaking**: Updated dependency `gianfriaur/opcua-php-client` from `^1.1` to `^2.0`.
- **Breaking**: `browse()` and `browseWithContinuation()` `$direction` parameter changed from `int` to `BrowseDirection` enum. Replace raw integers (`0`, `1`) with `BrowseDirection::Forward`, `BrowseDirection::Inverse`, or `BrowseDirection::Both`.
- Updated CI test server suite from `opcua-test-server-suite@v1.1.2` to `@v1.1.4`.
- Method whitelist expanded from 18 to 32 methods to support all new v2.0.0 operations.

### Added

- **Connection state management.** `isConnected()`, `getConnectionState()`, and `reconnect()` are now available on `ManagedClient`. Connection state (`Disconnected`, `Connected`, `Broken`) is queried from the daemon's underlying `Client`.
- **Configurable timeout.** `setTimeout(float)` / `getTimeout()` — configure the OPC UA operation timeout (default 5s). Applied to the daemon's `Client` before connection.
- **Auto-retry mechanism.** `setAutoRetry(int)` / `getAutoRetry()` — configure automatic reconnection attempts on `ConnectionException`. Default: 0 if never connected, 1 after first successful connection.
- **Automatic batching.** `setBatchSize(int)` / `getBatchSize()` — transparent batching for `readMulti()` and `writeMulti()`. Server operation limits are auto-discovered on connect. `getServerMaxNodesPerRead()` / `getServerMaxNodesPerWrite()` query the discovered values. `setBatchSize(0)` disables batching.
- **BrowseDirection enum.** `BrowseDirection::Forward`, `BrowseDirection::Inverse`, `BrowseDirection::Both` replace raw integer direction parameters.
- **`browseAll()` method.** Automatically follows all continuation points and returns the complete list of `ReferenceDescription` objects.
- **Recursive browsing.** `browseRecursive()` performs a full tree traversal with configurable max depth and cycle detection, returning `BrowseNode[]`. `setDefaultBrowseMaxDepth(int)` / `getDefaultBrowseMaxDepth()` configure the default depth (10). `-1` for unlimited (hardcapped at 256).
- **Path resolution.** `translateBrowsePaths()` translates browse paths to NodeIds. `resolveNodeId(string $path)` resolves human-readable paths like `/Objects/Server/ServerStatus` with support for namespaced segments (`2:Temperature`).
- **BrowseNode serialization.** `TypeSerializer` now serializes/deserializes `BrowseNode` trees, `BrowseDirection` enum, and `ConnectionState` enum.
- **New IPC config keys.** `open` command now accepts `opcuaTimeout`, `autoRetry`, `batchSize`, and `defaultBrowseMaxDepth` in the `config` payload.
- Unit tests for new types: `BrowseDirection`, `BrowseNode`, `ConnectionState` serialization roundtrips (18 new assertions).
- Unit tests for `ManagedClient` configuration: timeout, auto-retry, batching, browse depth, connection state (15 new tests).
- Unit tests for setter rejection via method whitelist (`setTimeout`, `setAutoRetry`, etc. are blocked).
- Integration tests for: browse recursive (6 tests), translate browse path (4 tests), connection state (5 tests), timeout/batching/auto-retry (6 tests).

## [1.1.0] - 2026-03-18

### Changed

- Updated dependency `gianfriaur/opcua-php-client` from `^1.0` to `^1.1`, requiring the new auto-generated certificate feature introduced in that release.

### Added

- **Auto-generated client certificate support.** When a secure connection is opened through the daemon with `SecurityPolicy` and `SecurityMode` configured but no `clientCertPath`/`clientKeyPath` provided, the underlying `Client` automatically generates an in-memory self-signed certificate. The behaviour is transparent and inherited from `opcua-php-client` v1.1 — no changes required in `ManagedClient` or `CommandHandler`.
- Unit and integration tests for the auto-generated certificate flow.
