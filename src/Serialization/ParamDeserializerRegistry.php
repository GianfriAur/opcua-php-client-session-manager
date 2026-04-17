<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Serialization;

use InvalidArgumentException;

/**
 * Holds the ordered list of {@see ParamDeserializerInterface} implementations
 * consulted by {@see \PhpOpcua\SessionManager\Daemon\CommandHandler} to decode
 * the IPC parameters of a whitelisted method.
 *
 * Third-party modules with custom service methods can register their own
 * deserializer via {@see self::register()} without touching the command
 * handler — the one built-in `BuiltInParamDeserializer` handles every method
 * shipped with this package.
 */
class ParamDeserializerRegistry
{
    /** @var ParamDeserializerInterface[] */
    private array $deserializers = [];

    /**
     * @param ParamDeserializerInterface $deserializer
     * @return void
     */
    public function register(ParamDeserializerInterface $deserializer): void
    {
        $this->deserializers[] = $deserializer;
    }

    /**
     * @param string $method
     * @param array $params
     * @return array
     * @throws InvalidArgumentException If no registered deserializer handles `$method`.
     */
    public function deserialize(string $method, array $params): array
    {
        foreach ($this->deserializers as $deserializer) {
            if ($deserializer->supports($method)) {
                return $deserializer->deserialize($method, $params);
            }
        }

        throw new InvalidArgumentException(sprintf(
            'No ParamDeserializer registered for method "%s". '
            . 'Register a ParamDeserializerInterface implementation on the registry to handle it.',
            $method,
        ));
    }

    /**
     * @param string $method
     * @return bool
     */
    public function supports(string $method): bool
    {
        foreach ($this->deserializers as $deserializer) {
            if ($deserializer->supports($method)) {
                return true;
            }
        }

        return false;
    }
}
