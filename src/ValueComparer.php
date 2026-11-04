<?php

declare(strict_types=1);

namespace Light\Entity;

/** Defines model-value equality and an independent snapshot for mutable values. */
final readonly class ValueComparer
{
    private \Closure $compare;
    private \Closure $copy;

    public function __construct(callable $equals, callable $snapshot)
    {
        $this->compare = \Closure::fromCallable($equals);
        $this->copy = \Closure::fromCallable($snapshot);
    }

    public function equals(mixed $left, mixed $right): bool
    {
        return $left === null || $right === null ? $left === $right : ($this->compare)($left, $right);
    }

    public function snapshot(mixed $value): mixed
    {
        return $value === null ? null : ($this->copy)($value);
    }
}
