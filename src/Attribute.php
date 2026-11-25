<?php

/*
* File:     Attribute.php
* Category: -
* Author:   M. Goldenbaum
* Created:  01.01.21 20:17
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;

use Webklex\PHPIMAP\Exceptions\InvalidMessageDateException;

/**
 * Class Attribute.
 */
final class Attribute implements \ArrayAccess, \Stringable
{
    /**
     * String representation of date values (same output as Carbon's __toString() had).
     */
    private const string DATE_STRING_FORMAT = 'Y-m-d H:i:s';

    /**
     * Value holder.
     */
    private array $values = [];

    public function __construct(public string $name, mixed $value = null)
    {
        $this->add($value);
    }

    /**
     * Return the stringified attribute.
     */
    public function __toString(): string
    {
        return implode(', ', array_map(
            static fn (mixed $value): string => $value instanceof \DateTimeInterface
                ? $value->format(self::DATE_STRING_FORMAT)
                : (string) $value,
            $this->values,
        ));
    }

    /**
     * Convert instance to array.
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Convert the first value to a date object.
     *
     * @throws InvalidMessageDateException
     */
    public function toDate(): \DateTimeImmutable
    {
        $date = $this->first();
        if ($date instanceof \DateTimeImmutable) {
            return $date;
        }

        try {
            return new \DateTimeImmutable($date);
        } catch (\DateMalformedStringException $e) {
            throw new InvalidMessageDateException("Invalid date: {$date}", 0, $e);
        }
    }

    /**
     * Determine if a value exists at an offset.
     *
     * @param int|string $offset
     */
    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return \array_key_exists($offset, $this->values);
    }

    /**
     * Get a value at a given offset.
     *
     * @param int|string $offset
     */
    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$offset];
    }

    /**
     * Set the value at a given offset.
     *
     * @param null|int|string $offset
     */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (null === $offset) {
            $this->values[] = $value;
        } else {
            $this->values[$offset] = $value;
        }
    }

    /**
     * Unset the value at a given offset.
     *
     * @param int|string $offset
     */
    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        unset($this->values[$offset]);
    }

    /**
     * Add one or more values to the attribute.
     */
    public function add(mixed $value, bool $strict = false): self
    {
        if (\is_array($value)) {
            return $this->merge($value, $strict);
        }

        if (null !== $value) {
            $this->attach($value, $strict);
        }

        return $this;
    }

    /**
     * Merge a given array of values with the current values array.
     *
     * @return $this
     */
    public function merge(array $values, bool $strict = false): self
    {
        foreach ($values as $value) {
            $this->attach($value, $strict);
        }

        return $this;
    }

    /**
     * Check if the attribute contains the given value.
     */
    public function contains(mixed $value): bool
    {
        return \in_array($value, $this->values, true);
    }

    /**
     * Attach a given value to the current value array.
     */
    public function attach(mixed $value, bool $strict = false): void
    {
        if (!$strict || !$this->contains($value)) {
            $this->values[] = $value;
        }
    }

    /**
     * Alias method for self::get().
     */
    public function all(): array
    {
        reset($this->values);

        return $this->values;
    }

    /**
     * Get the first value if possible.
     */
    public function first(): mixed
    {
        return array_first($this->values);
    }

    /**
     * Get the last value if possible.
     */
    public function last(): mixed
    {
        return array_last($this->values);
    }

    /**
     * Get the number of values.
     */
    public function count(): int
    {
        return count($this->values);
    }

    public function isEmpty(): bool
    {
        return [] === $this->values;
    }
}
