<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Serialization;

/**
 * Deserialize the raw IPC parameters of a whitelisted method into the typed
 * argument list that the underlying `Client` method expects.
 *
 * Implementations declare which method names they handle via {@see self::supports()}
 * and provide the concrete decoding in {@see self::deserialize()}. The registry
 * consults implementations in registration order; the first match wins.
 */
interface ParamDeserializerInterface
{
    /**
     * @param string $method The method name being dispatched over IPC.
     * @return bool True if this deserializer can decode parameters for `$method`.
     */
    public function supports(string $method): bool;

    /**
     * @param string $method
     * @param array $params Raw JSON-decoded parameters from the IPC request.
     * @return array Positional argument list suitable for `$client->$method(...$args)`.
     */
    public function deserialize(string $method, array $params): array;
}
