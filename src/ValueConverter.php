<?php

declare(strict_types=1);

namespace Light\Entity;

/** Converts one model property to/from a scalar database value. Null bypasses conversion. */
final readonly class ValueConverter
{
    private \Closure $toProvider;
    private \Closure $fromProvider;

    public function __construct(callable $toProvider, callable $fromProvider)
    {
        $this->toProvider = \Closure::fromCallable($toProvider);
        $this->fromProvider = \Closure::fromCallable($fromProvider);
    }

    public function convertToProvider(mixed $value): mixed
    {
        $converted = $value === null ? null : ($this->toProvider)($value);
        if ($converted !== null && !is_scalar($converted)) {
            throw new \InvalidArgumentException('A converter must produce a scalar or null provider value.');
        }
        return $converted;
    }

    public function convertFromProvider(mixed $value): mixed
    {
        return $value === null ? null : ($this->fromProvider)($value);
    }
}
