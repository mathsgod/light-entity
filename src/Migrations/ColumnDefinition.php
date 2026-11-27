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
        public ?int $precision = null,
        public ?int $scale = null,
        public ?string $columnType = null,
    ) {
        if ($name === '' || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Column names must be non-empty and contain no NUL bytes.');
        }
        self::validateType($type, $length, $precision, $scale);
        if ($columnType !== null) {
            self::validateColumnType($columnType);
            if ($autoIncrement) {
                throw new \InvalidArgumentException('Auto-increment requires the default integer column type.');
            }
        }
        if ($type !== 'string' && $length !== null) {
            throw new \InvalidArgumentException('Length is only supported for string columns.');
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

    /** @internal */
    public static function validateColumnType(string $type): void
    {
        if (!preg_match('/\A[A-Z][A-Z0-9_]*(?: *\( *[0-9]+ *(?:, *[0-9]+ *)?\))?(?: +UNSIGNED)?\z/i', $type)) {
            throw new \InvalidArgumentException('Expected a SQL type name with optional numeric arguments or UNSIGNED.');
        }
    }

    /** @internal */
    public static function validateType(string $type, ?int $length, ?int $precision, ?int $scale): void
    {
        if (!in_array($type, ['integer', 'string', 'boolean', 'text', 'decimal', 'date', 'datetime', 'binary'], true)) {
            throw new \InvalidArgumentException('Unsupported column type.');
        }
        if ($type === 'string' && ($length === null || $length < 1 || $length > 65535)) {
            throw new \InvalidArgumentException('String length must be between 1 and 65535.');
        }
        if ($type === 'decimal') {
            if ($precision === null || $precision < 1 || $precision > 65
                || $scale === null || $scale < 0 || $scale > 30 || $scale > $precision) {
                throw new \InvalidArgumentException(
                    'Decimal requires precision from 1 to 65 and scale from 0 to 30, no greater than precision.'
                );
            }
        } elseif ($precision !== null || $scale !== null) {
            throw new \InvalidArgumentException('Precision and scale are only supported for decimal columns.');
        }
    }
}
