<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Ipc;

use PhpOpcua\SessionManager\Exception\DaemonException;

/**
 * Base class implementing the NDJSON framing loop on top of a PHP stream resource.
 *
 * Concrete transports (Unix socket, TCP loopback, named pipe) produce the
 * underlying resource in their {@see self::openStream()} hook; everything else —
 * binary-mode handling, `\n` framing, connect/close lifecycle, timeout — lives
 * here so that adding a new transport amounts to writing a single 20-line
 * `openStream()` override.
 */
abstract class AbstractStreamTransport implements TransportInterface
{
    /** @var ?resource */
    private $stream = null;

    /**
     * @param float $timeout Read timeout in seconds applied to the stream after connect.
     */
    public function __construct(protected readonly float $timeout = 30.0)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function connect(): void
    {
        if ($this->stream !== null) {
            return;
        }

        $this->stream = $this->openStream();

        stream_set_timeout($this->stream, (int) floor($this->timeout), (int) (fmod($this->timeout, 1.0) * 1_000_000));
        stream_set_write_buffer($this->stream, 0);
    }

    /**
     * {@inheritDoc}
     */
    public function sendLine(string $payload): void
    {
        if ($this->stream === null) {
            throw new DaemonException(sprintf(
                'Cannot sendLine on a disconnected transport (%s): call connect() first.',
                $this->describeEndpoint(),
            ));
        }
        if (str_contains($payload, "\n")) {
            throw new DaemonException('NDJSON framing violation: payload contains a raw newline byte.');
        }

        $toWrite = $payload . "\n";
        $written = 0;
        $length = strlen($toWrite);
        while ($written < $length) {
            $n = @fwrite($this->stream, substr($toWrite, $written));
            if ($n === false || $n === 0) {
                throw new DaemonException(sprintf(
                    'Short write on transport %s: %d / %d bytes sent before the stream refused more.',
                    $this->describeEndpoint(),
                    $written,
                    $length,
                ));
            }
            $written += $n;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function receiveLine(): ?string
    {
        if ($this->stream === null) {
            throw new DaemonException(sprintf(
                'Cannot receiveLine on a disconnected transport (%s): call connect() first.',
                $this->describeEndpoint(),
            ));
        }

        $line = stream_get_line($this->stream, 1_048_576, "\n");
        $meta = stream_get_meta_data($this->stream);
        if ($meta['timed_out'] ?? false) {
            throw new DaemonException(sprintf(
                'Read timeout on transport %s after %.3fs.',
                $this->describeEndpoint(),
                $this->timeout,
            ));
        }
        if ($line === false || $line === '') {
            if (feof($this->stream)) {
                return null;
            }
            throw new DaemonException(sprintf(
                'Read error on transport %s: stream_get_line returned no data without EOF.',
                $this->describeEndpoint(),
            ));
        }

        return $line;
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isConnected(): bool
    {
        return $this->stream !== null;
    }

    /**
     * Open the underlying PHP stream resource. Called once per connect.
     *
     * Implementations must return a valid, binary-safe stream resource; the
     * base class takes care of timeout + write buffering afterwards.
     *
     * @return resource
     * @throws DaemonException If the connect attempt fails.
     */
    abstract protected function openStream();
}
