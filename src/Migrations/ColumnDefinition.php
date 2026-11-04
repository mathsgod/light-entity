<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

/** Immutable column metadata captured after the table callback completes. */
final readonly class ColumnDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public ?int $length,
        public bool $nullable,
        public bool $autoIncrement,
        public bool $primaryKey,
        public bool $hasDefault = false,
        public mixed $defaultValue = null,
    ) {
        if ($name === '' || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Column names must be non-empty and contain no NUL bytes.');
        }
        if (!in_array($type, ['integer', 'string'], true)
            || ($type === 'integer' && $length !== null)
            || ($type === 'string' && ($length === null || $length < 1 || $length > 65535))) {
            throw new \InvalidArgumentException('Use integer or string with a length between 1 and 65535.');
        }
        if ($hasDefault && ($defaultValue !== null && !is_scalar($defaultValue)
            || is_float($defaultValue) && !is_finite($defaultValue)
            || $defaultValue === null && !$nullable
            || $autoIncrement)) {
            throw new \InvalidArgumentException('Invalid column default.');
        }
        if ($primaryKey && $nullable) {
            throw new \InvalidArgumentException('A primary-key column cannot be nullable.');
        }
        if ($autoIncrement && ($type !== 'integer' || !$primaryKey)) {
            throw new \InvalidArgumentException('Auto-increment requires an integer primary key.');
        }
    }
}
