<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

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


    public function boolean(string $name): ColumnBuilder
    {
        return $this->columns[] = new ColumnBuilder($name, 'boolean');
    }

    public function text(string $name): ColumnBuilder
    {
        return $this->columns[] = new ColumnBuilder($name, 'text');
    }

    public function decimal(string $name, int $precision = 18, int $scale = 2): ColumnBuilder
    {
        return $this->columns[] = new ColumnBuilder($name, 'decimal', precision: $precision, scale: $scale);
    }

    public function date(string $name): ColumnBuilder
    {
        return $this->columns[] = new ColumnBuilder($name, 'date');
    }

    public function dateTime(string $name): ColumnBuilder
    {
        return $this->columns[] = new ColumnBuilder($name, 'datetime');
    }

    public function binary(string $name): ColumnBuilder
    {
        return $this->columns[] = new ColumnBuilder($name, 'binary');
    }

    public function build(string $table): CreateTableOperation
    {
        return new CreateTableOperation($table, array_map(fn ($column) => $column->build(), $this->columns));
    }
}
