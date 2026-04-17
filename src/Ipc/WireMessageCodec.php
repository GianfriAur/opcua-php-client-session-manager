<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Ipc;

use PhpOpcua\Client\Exception\EncodingException;
use PhpOpcua\Client\Wire\WireTypeRegistry;
use PhpOpcua\SessionManager\Exception\DaemonException;

/**
 * NDJSON-framed typed-envelope codec used on both sides of the session-manager
 * IPC boundary.
 *
 * Each frame is a single JSON object terminated by `\n`. Two envelope shapes
 * are defined:
 *
 * **Request**
 *   ```json
 *   {"id": <int>, "t": "req", "method": "<string>", "args": [<wire_value>...]}
 *   ```
 *
 * **Response (success)**
 *   ```json
 *   {"id": <int>, "t": "res", "ok": true, "data": <wire_value>}
 *   ```
 *
 * **Response (error)**
 *   ```json
 *   {"id": <int>, "t": "res", "ok": false, "error": {"class": "<string>", "message": "<string>"}}
 *   ```
 *
 * `<wire_value>` is whatever the attached {@see WireTypeRegistry} emits:
 * scalars and arrays pass through, typed values become `{"__t": "<id>", ...}`.
 * Because the registry is the only path for typed payloads, the codec can
 * never instantiate a class that is not explicitly registered — which is the
 * property that keeps this transport safe against `unserialize()`-style gadget
 * chains by construction.
 *
 * The codec is strict on envelope shape: missing `id`, wrong `t`, or absent
 * `ok` on a response all surface as {@see DaemonException} so that wire
 * drift is loud instead of silently masked.
 *
 * ### Limits
 *
 * - `maxFrameBytes` caps the JSON payload size before decoding (default 16 MiB).
 * - `maxDepth` caps the JSON nesting depth (default 32). Both mitigate DoS
 *   without blocking realistic OPC UA result sets.
 */
final class WireMessageCodec
{
    public const T_REQUEST = 'req';
    public const T_RESPONSE = 'res';

    /**
     * @param WireTypeRegistry $registry The shared type registry used for encode/decode.
     * @param int $maxFrameBytes Hard cap on every encoded or decoded JSON payload, in bytes.
     * @param int $maxDepth Hard cap on JSON nesting depth at decode time.
     */
    public function __construct(
        private readonly WireTypeRegistry $registry,
        private readonly int $maxFrameBytes = 16 * 1024 * 1024,
        private readonly int $maxDepth = 32,
    ) {
    }

    /**
     * Encode a request envelope to its NDJSON frame bytes (without the
     * trailing `\n` — the transport appends it).
     *
     * @param int $id Correlation id, ≥ 1.
     * @param string $method Target method name.
     * @param array<int, mixed> $args Positional arguments; each is passed through the registry encoder.
     * @return string
     * @throws EncodingException If any argument cannot be encoded.
     * @throws DaemonException If the resulting JSON exceeds `maxFrameBytes`.
     */
    public function encodeRequest(int $id, string $method, array $args): string
    {
        $encodedArgs = [];
        foreach ($args as $k => $v) {
            $encodedArgs[$k] = $this->registry->encode($v);
        }

        return $this->encodeEnvelope([
            'id' => $id,
            't' => self::T_REQUEST,
            'method' => $method,
            'args' => $encodedArgs,
        ]);
    }

    /**
     * Encode a success response envelope.
     *
     * @param int $id Correlation id matching the originating request.
     * @param mixed $data The value to return; passed through the registry encoder.
     * @return string
     * @throws EncodingException If `$data` cannot be encoded.
     * @throws DaemonException If the resulting JSON exceeds `maxFrameBytes`.
     */
    public function encodeOkResponse(int $id, mixed $data): string
    {
        return $this->encodeEnvelope([
            'id' => $id,
            't' => self::T_RESPONSE,
            'ok' => true,
            'data' => $this->registry->encode($data),
        ]);
    }

    /**
     * Encode an error response envelope. The exception's short class name and
     * message are transmitted; no stack trace, no nested cause — intentionally
     * narrow to avoid leaking daemon internals across the boundary.
     *
     * @param int $id Correlation id matching the originating request.
     * @param string $errorClass Short class name of the exception (no namespace).
     * @param string $message Short human-readable error description.
     * @return string
     * @throws DaemonException If the resulting JSON exceeds `maxFrameBytes`.
     */
    public function encodeErrorResponse(int $id, string $errorClass, string $message): string
    {
        return $this->encodeEnvelope([
            'id' => $id,
            't' => self::T_RESPONSE,
            'ok' => false,
            'error' => ['class' => $errorClass, 'message' => $message],
        ]);
    }

    /**
     * Decode an inbound NDJSON frame into its structured envelope.
     *
     * @param string $frame A single JSON object with the trailing `\n` already stripped.
     * @return array{id: int, t: string, method?: string, args?: array, ok?: bool, data?: mixed, error?: array{class: string, message: string}}
     * @throws DaemonException On framing / shape violations.
     * @throws EncodingException On registry-level decode failures.
     */
    public function decodeFrame(string $frame): array
    {
        if (strlen($frame) > $this->maxFrameBytes) {
            throw new DaemonException(sprintf(
                'IPC frame exceeds %d byte cap (got %d bytes). Adjust maxFrameBytes or split the payload.',
                $this->maxFrameBytes,
                strlen($frame),
            ));
        }

        try {
            $decoded = json_decode($frame, true, $this->maxDepth, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DaemonException('Malformed IPC frame: ' . $e->getMessage(), 0, $e);
        }

        if (! is_array($decoded) || (count($decoded) > 0 && array_is_list($decoded))) {
            throw new DaemonException('IPC frame: top-level JSON value must be an object.');
        }

        if (! isset($decoded['id']) || ! is_int($decoded['id'])) {
            throw new DaemonException('IPC frame: missing or non-integer "id" field.');
        }

        $t = $decoded['t'] ?? null;
        if ($t !== self::T_REQUEST && $t !== self::T_RESPONSE) {
            throw new DaemonException(sprintf('IPC frame: "t" must be "%s" or "%s".', self::T_REQUEST, self::T_RESPONSE));
        }

        if ($t === self::T_REQUEST) {
            if (! isset($decoded['method']) || ! is_string($decoded['method'])) {
                throw new DaemonException('IPC request: missing or non-string "method" field.');
            }
            if (! isset($decoded['args']) || ! is_array($decoded['args'])) {
                throw new DaemonException('IPC request: missing or non-array "args" field.');
            }
            $decoded['args'] = array_map(fn ($v) => $this->registry->decode($v), $decoded['args']);

            return $decoded;
        }

        if (! isset($decoded['ok']) || ! is_bool($decoded['ok'])) {
            throw new DaemonException('IPC response: missing or non-boolean "ok" field.');
        }

        if ($decoded['ok']) {
            if (array_key_exists('data', $decoded)) {
                $decoded['data'] = $this->registry->decode($decoded['data']);
            }

            return $decoded;
        }

        if (! isset($decoded['error']) || ! is_array($decoded['error'])
            || ! isset($decoded['error']['class'], $decoded['error']['message'])
            || ! is_string($decoded['error']['class']) || ! is_string($decoded['error']['message'])
        ) {
            throw new DaemonException('IPC error response: "error" must be {class: string, message: string}.');
        }

        return $decoded;
    }

    /**
     * Expose the wrapped registry so that clients sharing the codec can
     * register additional types (e.g. on `__describe__` at session attach).
     *
     * @return WireTypeRegistry
     */
    public function registry(): WireTypeRegistry
    {
        return $this->registry;
    }

    /**
     * @param array<string, mixed> $envelope
     * @return string
     * @throws DaemonException
     */
    private function encodeEnvelope(array $envelope): string
    {
        try {
            $json = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new DaemonException('Cannot encode IPC envelope: ' . $e->getMessage(), 0, $e);
        }
        if (strlen($json) > $this->maxFrameBytes) {
            throw new DaemonException(sprintf(
                'Encoded IPC envelope exceeds %d byte cap (got %d bytes).',
                $this->maxFrameBytes,
                strlen($json),
            ));
        }

        return $json;
    }
}
