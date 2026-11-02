<?php

declare(strict_types=1);

namespace PhpEntity\Migrations;

final class TableBuilder
{
    /** @var list<ColumnBuilder> */
    private array $columns = [];

    public function integer(string $name): ColumnBuilder
    {
        return $this->columns[] = new ColumnBuilder($name, 'integer');
    }

    public function string(string $name, int $length = 255): ColumnBuilder
    {
        return $this->columns[] = new ColumnBuilder($name, 'string', $length);
    }

    public function build(string $table): CreateTableOperation
    {
        return new CreateTableOperation($table, array_map(fn ($column) => $column->build(), $this->columns));
    }
}
