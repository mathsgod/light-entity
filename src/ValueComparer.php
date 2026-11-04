<?php

declare(strict_types=1);

namespace Light\Entity;

/** Defines model-value equality and an independent snapshot for mutable values. */
final readonly class ValueComparer
{
    private \Closure $compare;
    private \Closure $copy;
    private ?\Closure $providerCopy;

    public function __construct(callable $equals, callable $snapshot, ?callable $snapshotFromProvider = null)
    {
        $this->compare = \Closure::fromCallable($equals);
        $this->copy = \Closure::fromCallable($snapshot);
        $this->providerCopy = $snapshotFromProvider === null ? null : \Closure::fromCallable($snapshotFromProvider);
    }

    public function equals(mixed $left, mixed $right): bool
    {
        return $left === null || $right === null ? $left === $right : ($this->compare)($left, $right);
    }

    public function snapshot(mixed $value): mixed
    {
        return $value === null ? null : ($this->copy)($value);
    }
    /** For fresh, converter-produced values only; falls back to the general snapshot callback. */
    public function snapshotFromProvider(mixed $value): mixed
    {
        return $this->providerCopy === null ? $this->snapshot($value)
            : ($value === null ? null : ($this->providerCopy)($value));
    }
}
