<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

/** Shared validation; names are quoted as identifiers by the SQL generator. @internal */
final class SchemaNames
{
    public static function identifier(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Identifiers must be non-empty and contain no NUL bytes.');
        }
    }

    public static function table(string $name): void
    {
        self::identifier($name);
        $parts = explode('.', $name);
        if (count($parts) > 2 || in_array('', $parts, true)) {
            throw new \InvalidArgumentException('Expected table name or schema.table.');
        }
    }

    public static function columns(array $names): void
    {
        if ($names === [] || !array_is_list($names)) {
            throw new \InvalidArgumentException('Columns must be a non-empty list of identifiers.');
        }
        foreach ($names as $name) {
            if (!is_string($name)) { throw new \InvalidArgumentException('Column names must be strings.'); }
            self::identifier($name);
        }
        if (count(array_unique(array_map('strtolower', $names))) !== count($names)) {
            throw new \InvalidArgumentException('Duplicate column names are not allowed.');
        }
    }
}
