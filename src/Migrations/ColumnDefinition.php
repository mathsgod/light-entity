<?php

declare(strict_types=1);

namespace PhpEntity\Migrations;

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
    ) {
        if ($name === '' || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Column names must be non-empty and contain no NUL bytes.');
        }
        if (!in_array($type, ['integer', 'string'], true)
            || ($type === 'integer' && $length !== null)
            || ($type === 'string' && ($length === null || $length < 1 || $length > 65535))) {
            throw new \InvalidArgumentException('Use integer or string with a length between 1 and 65535.');
        }
        if ($primaryKey && $nullable) {
            throw new \InvalidArgumentException('A primary-key column cannot be nullable.');
        }
        if ($autoIncrement && ($type !== 'integer' || !$primaryKey)) {
            throw new \InvalidArgumentException('Auto-increment requires an integer primary key.');
        }
    }
}
