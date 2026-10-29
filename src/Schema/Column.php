<?php

declare(strict_types=1);

namespace PhpEntity\Schema;

final readonly class Column
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public bool $primary = false,
        public bool $generated = false,
        public bool $computed = false,
        public mixed $default = null,
    ) {}

    public function convert(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $type = strtolower($this->type);
        // Exact decimals and large integers must not silently lose precision.
        if (preg_match('/\b(bool|boolean)\b/', $type)) {
            return (bool) $value;
        }
        if (str_contains($type, 'int')) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            return $integer === false ? (string) $value : $integer;
        }
        if (preg_match('/\b(float|double|real)\b/', $type)) {
            return (float) $value;
        }
        if ($type === '') {
            return $value; // SQLite untyped column
        }
        return (string) $value; // Includes decimal, JSON, date/time and binary.
    }
}
