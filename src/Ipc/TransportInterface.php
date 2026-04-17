<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Ipc;

use PhpOpcua\SessionManager\Exception\DaemonException;

/**
 * Transport-layer abstraction for client-side IPC between {@see \PhpOpcua\SessionManager\Client\ManagedClient}
 * and the session-manager daemon.
 *
 * Implementations wrap a byte-oriented stream (Unix socket, TCP loopback, named
 * pipe on Windows in stream mode, …) and expose a minimal send / receive-line
 * API suitable for NDJSON framing (one JSON value per `\n`-terminated line).
 *
 * The abstraction is intentionally narrow so that the wire format
 * ({@see WireMessageCodec}) can be reused unchanged across transports — a
 * property that becomes load-bearing when adding a Windows alternative to the
 * Unix socket default.
 *
 * ### Framing contract
 *
 * - {@see self::sendLine()} writes a single payload followed by exactly one
 *   `\n` terminator. The payload MUST NOT itself contain `\n`.
 * - {@see self::receiveLine()} reads bytes until it sees `\n` and returns the
 *   bytes before the terminator (the terminator is consumed). On clean EOF
 *   before any data arrives, it returns `null`.
 *
 * Both methods open the stream in binary mode so that Windows text-mode
 * `\n` ↔ `\r\n` translation never silently rewrites the frame boundary.
 *
 * ### Error semantics
 *
 * Transport-level failures (connect refused, broken pipe, auth timeout, …)
 * surface as {@see DaemonException}. Higher-level protocol errors (unknown
 * wire type id, malformed envelope) belong to the codec layer and bubble up
 * as appropriate domain exceptions from there.
 */
interface TransportInterface
{
    /**
     * Establish the underlying byte stream. Safe to call once per transport
     * instance. Subsequent calls on an already-open transport are no-ops.
     *
     * @return void
     * @throws DaemonException If the underlying connect fails.
     */
    public function connect(): void;

    /**
     * Write one NDJSON line to the stream.
     *
     * The implementation appends a single `\n` byte after `$payload`. Callers
     * must guarantee `$payload` itself contains no `\n`.
     *
     * @param string $payload Raw line bytes (no terminator).
     * @return void
     * @throws DaemonException If the write fails or the stream is not connected.
     */
    public function sendLine(string $payload): void;

    /**
     * Read one NDJSON line from the stream.
     *
     * @return ?string The line bytes without the trailing `\n`, or `null` when
     *                 the peer closed the stream before any data arrived.
     * @throws DaemonException If the read fails mid-frame.
     */
    public function receiveLine(): ?string;

    /**
     * Close the underlying byte stream. Idempotent.
     *
     * @return void
     */
    public function close(): void;

    /**
     * Whether the transport is currently connected.
     *
     * @return bool
     */
    public function isConnected(): bool;

    /**
     * Human-readable endpoint description for log / error messages (e.g.
     * `unix:///tmp/opcua-session-manager.sock` or `tcp://127.0.0.1:9990`).
     *
     * @return string
     */
    public function describeEndpoint(): string;
}
