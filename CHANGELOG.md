# Changelog

## [1.1.0] - 2026-03-18

### Changed

- Updated dependency `gianfriaur/opcua-php-client` from `^1.0` to `^1.1`, requiring the new auto-generated certificate feature introduced in that release.

### Added

- **Auto-generated client certificate support.** When a secure connection is opened through the daemon with `SecurityPolicy` and `SecurityMode` configured but no `clientCertPath`/`clientKeyPath` provided, the underlying `Client` automatically generates an in-memory self-signed certificate. The behaviour is transparent and inherited from `opcua-php-client` v1.1 — no changes required in `ManagedClient` or `CommandHandler`.
- Unit and integration tests for the auto-generated certificate flow.
