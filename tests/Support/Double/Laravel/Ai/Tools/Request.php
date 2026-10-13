<?php

declare(strict_types=1);

namespace Laravel\Ai\Tools;

final class Request implements \ArrayAccess
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values)
    {
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && array_key_exists($offset, $this->values);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!$this->offsetExists($offset)) {
            throw new \OutOfBoundsException('Missing tool argument.');
        }

        return $this->values[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_string($offset)) {
            $this->values[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_string($offset)) {
            unset($this->values[$offset]);
        }
    }
}
