<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Adapter;

use SpacedTracker\Ai\Exception\IntegrationException;

/**
 * Calls into PHP AI libraries that are not hard dependencies.
 *
 * Class names are assembled at runtime so a missing package does not fatal
 * while this file is loading.
 *
 * @internal
 */
final class OptionalSdk
{
    public static function className(string ...$parts): string
    {
        return implode('\\', $parts);
    }

    /**
     * @param list<mixed> $arguments
     */
    public static function new(string $class, array $arguments = []): object
    {
        if (!class_exists($class)) {
            throw new IntegrationException($class . ' is not installed.');
        }

        /** @var class-string $class */
        return new $class(...$arguments);
    }

    public static function call(object $object, string $method, mixed ...$arguments): mixed
    {
        if (!is_callable([$object, $method])) {
            throw new IntegrationException($method . ' is not callable.');
        }

        return $object->{$method}(...$arguments);
    }

    public static function callStatic(string $class, string $method, mixed ...$arguments): mixed
    {
        $callback = [$class, $method];
        if (!is_callable($callback)) {
            throw new IntegrationException($class . '::' . $method . ' is not callable.');
        }

        /** @var callable $callback */
        return forward_static_call($callback, ...$arguments);
    }
}
