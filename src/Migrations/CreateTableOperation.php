<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

final readonly class CreateTableOperation
{
    /** @param non-empty-list<ColumnDefinition> $columns */
    public function __construct(public string $table, public array $columns)
    {
        $parts = explode('.', $table);
        if (count($parts) > 2 || in_array('', $parts, true) || str_contains($table, "\0")) {
            throw new \InvalidArgumentException('Expected a table name or schema.table.');
        }
        if ($columns === []) {
            throw new \InvalidArgumentException('A table must contain at least one column.');
        }
        $names = [];
        $generated = 0;
        foreach ($columns as $column) {
            if (!$column instanceof ColumnDefinition) {
                throw new \InvalidArgumentException('Expected column definitions.');
            }
            $name = strtolower($column->name);
            if (isset($names[$name])) {
                throw new \InvalidArgumentException('Duplicate column: '.$column->name);
            }
            $names[$name] = true;
            $generated += (int) $column->autoIncrement;
        }
        if ($generated > 1) {
            throw new \InvalidArgumentException('Only one auto-increment column is supported.');
        }
    }
}
