<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

/** Collects schema operations without connecting to or modifying a database. */
final class MigrationBuilder
{
    /** @var list<MigrationOperation> */
    private array $operations = [];

    /** @param callable(TableBuilder): void $configure */
    public function createTable(string $name, callable $configure): self
    {
        $table = new TableBuilder();
        $configure($table);
        $this->operations[] = $table->build($name);
        return $this;
    }

    /** @param callable(TableBuilder): mixed $configure Define exactly one column. */
    public function addColumn(string $table, callable $configure): self
    {
        return $this->columnOperation('add', $table, $configure);
    }

    /** Full replacement definition, including nullability and default. */
    public function alterColumn(string $table, callable $configure): self
    {
        return $this->columnOperation('alter', $table, $configure);
    }

    public function renameColumn(string $table, string $name, string $newName): self
    {
        $this->operations[] = new ColumnOperation('rename', $table, $name, newName: $newName);
        return $this;
    }

    public function dropColumn(string $table, string $name): self
    {
        $this->operations[] = new ColumnOperation('drop', $table, $name);
        return $this;
    }

    public function dropTable(string $table): self
    {
        $this->operations[] = new DropTableOperation($table);
        return $this;
    }

    /** @param non-empty-list<string> $columns */
    public function createIndex(string $table, string $name, array $columns, bool $unique = false): self
    {
        $this->operations[] = new IndexOperation('create', $table, $name, $columns, $unique);
        return $this;
    }

    public function dropIndex(string $table, string $name): self
    {
        $this->operations[] = new IndexOperation('drop', $table, $name);
        return $this;
    }

    /** @param non-empty-list<string> $columns @param non-empty-list<string> $principalColumns */
    public function addForeignKey(string $table, string $name, array $columns, string $principalTable,
        array $principalColumns, string $onDelete = 'NO ACTION', string $onUpdate = 'NO ACTION'): self
    {
        $this->operations[] = new ForeignKeyOperation('add', $table, $name, $columns, $principalTable,
            $principalColumns, strtoupper($onDelete), strtoupper($onUpdate));
        return $this;
    }

    public function dropForeignKey(string $table, string $name): self
    {
        $this->operations[] = new ForeignKeyOperation('drop', $table, $name);
        return $this;
    }

    private function columnOperation(string $action, string $table, callable $configure): self
    {
        $builder = new TableBuilder();
        $configure($builder);
        $columns = $builder->build($table)->columns;
        if (count($columns) !== 1) {
            throw new \InvalidArgumentException('Define exactly one column per addColumn()/alterColumn() operation.');
        }
        $this->operations[] = new ColumnOperation($action, $table, $columns[0]->name, $columns[0]);
        return $this;
    }

    /** @return list<MigrationOperation> */
    public function getOperations(): array
    {
        return $this->operations;
    }
}
