<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

use Light\Entity\Sql\MySqlDialect;
use Light\Entity\Sql\SqlDialect;
use Light\Entity\Sql\SqliteDialect;

/** DDL generation is separate from the LINQ query compiler. */
final readonly class MigrationSqlGenerator
{
    public function __construct(private SqlDialect $dialect)
    {
        if (!$dialect instanceof MySqlDialect && !$dialect instanceof SqliteDialect) {
            throw new \LogicException('DDL generation currently supports only MySQL and SQLite.');
        }
    }

    /** Compile the entire migration before executing any SQL. @return list<string> */
    public function generate(MigrationBuilder $migration): array
    {
        $statements = [];
        foreach ($migration->getOperations() as $operation) {
            $statements[] = match (true) {
                $operation instanceof CreateTableOperation => $this->createTable($operation),
                $operation instanceof DropTableOperation => 'DROP TABLE '.$this->dialect->quoteIdentifier($operation->table),
                $operation instanceof ColumnOperation => $this->columnOperation($operation),
                $operation instanceof IndexOperation => $this->indexOperation($operation),
                $operation instanceof ForeignKeyOperation => $this->foreignKeyOperation($operation),
                default => throw new \LogicException('Unsupported migration operation.'),
            };
        }
        return $statements;
    }

    private function createTable(CreateTableOperation $operation): string
    {
        $keys = array_values(array_filter($operation->columns, fn ($column) => $column->primaryKey));
        $composite = count($keys) > 1;
        if ($composite && $this->dialect instanceof SqliteDialect
            && array_filter($keys, fn ($column) => $column->autoIncrement)) {
            throw new \LogicException('SQLite does not support auto-increment in a composite primary key.');
        }
        if ($composite && $this->dialect instanceof MySqlDialect) {
            foreach ($keys as $index => $key) {
                if ($key->autoIncrement && $index !== 0) {
                    throw new \LogicException('MySQL requires the auto-increment column first in the primary key.');
                }
            }
        }
        $columns = array_map(fn ($column) => $this->columnDefinition($column, $composite), $operation->columns);
        if ($composite) {
            $columns[] = 'PRIMARY KEY ('.implode(', ', array_map(fn ($key) => $this->quoteColumn($key->name), $keys)).')';
        }
        return 'CREATE TABLE '.$this->dialect->quoteIdentifier($operation->table)
            ." (\n    ".implode(",\n    ", $columns)."\n)";
    }

    private function columnDefinition(ColumnDefinition $column, bool $composite = false): string
    {
        $name = $this->quoteColumn($column->name);
        $type = $column->type === 'integer'
            ? ($this->dialect instanceof SqliteDialect ? 'INTEGER' : 'INT')
            : 'VARCHAR('.$column->length.')';
        if (!$composite && $column->primaryKey && !$column->autoIncrement
            && $column->type === 'integer' && $this->dialect instanceof SqliteDialect) {
            $type = 'INT'; // INTEGER PRIMARY KEY would imply a rowid-generated key.
        }
        $sql = $name.' '.$type.($column->nullable ? ' NULL' : ' NOT NULL');
        if ($column->primaryKey && !$composite) { $sql .= ' PRIMARY KEY'; }
        if ($column->autoIncrement) {
            $sql .= $this->dialect instanceof MySqlDialect ? ' AUTO_INCREMENT' : ' AUTOINCREMENT';
        }
        if ($column->hasDefault) { $sql .= ' DEFAULT '.$this->literal($column->defaultValue); }
        return $sql;
    }

    private function columnOperation(ColumnOperation $operation): string
    {
        $prefix = 'ALTER TABLE '.$this->dialect->quoteIdentifier($operation->table);
        return match ($operation->action) {
            'add' => $prefix.' ADD COLUMN '.$this->columnDefinition($operation->definition),
            'alter' => $this->dialect instanceof MySqlDialect
                ? $prefix.' MODIFY COLUMN '.$this->columnDefinition($operation->definition)
                : throw new \LogicException('SQLite alterColumn() requires a table rebuild, which is not supported.'),
            'rename' => $prefix.' RENAME COLUMN '.$this->quoteColumn($operation->name).' TO '.$this->quoteColumn($operation->newName),
            'drop' => $prefix.' DROP COLUMN '.$this->quoteColumn($operation->name),
        };
    }

    private function indexOperation(IndexOperation $operation): string
    {
        $name = $this->quoteColumn($operation->name);
        $table = $this->dialect->quoteIdentifier($operation->table);
        if ($this->dialect instanceof SqliteDialect && str_contains($operation->table, '.')) {
            [$schema, $localTable] = explode('.', $operation->table);
            $name = $this->quoteColumn($schema).'.'.$name;
            $table = $this->quoteColumn($localTable); // SQLite qualifies the index, not the ON table.
        }
        if ($operation->action === 'drop') {
            return 'DROP INDEX '.$name.($this->dialect instanceof MySqlDialect ? ' ON '.$table : '');
        }
        return 'CREATE '.($operation->unique ? 'UNIQUE ' : '').'INDEX '.$name.' ON '.$table
            .' ('.implode(', ', array_map($this->quoteColumn(...), $operation->columns)).')';
    }

    private function foreignKeyOperation(ForeignKeyOperation $operation): string
    {
        if ($this->dialect instanceof SqliteDialect) {
            throw new \LogicException('SQLite adding/dropping foreign keys requires a table rebuild, which is not supported.');
        }
        $prefix = 'ALTER TABLE '.$this->dialect->quoteIdentifier($operation->table);
        if ($operation->action === 'drop') {
            return $prefix.' DROP FOREIGN KEY '.$this->quoteColumn($operation->name);
        }
        return $prefix.' ADD CONSTRAINT '.$this->quoteColumn($operation->name)
            .' FOREIGN KEY ('.implode(', ', array_map($this->quoteColumn(...), $operation->columns)).')'
            .' REFERENCES '.$this->dialect->quoteIdentifier($operation->principalTable)
            .' ('.implode(', ', array_map($this->quoteColumn(...), $operation->principalColumns)).')'
            .' ON DELETE '.$operation->onDelete.' ON UPDATE '.$operation->onUpdate;
    }

    private function literal(mixed $value): string
    {
        if ($value === null) { return 'NULL'; }
        if (is_bool($value)) { return $value ? '1' : '0'; }
        if (is_int($value) || is_float($value)) { return json_encode($value, JSON_THROW_ON_ERROR); }
        // Hex string literals avoid dependence on MySQL's NO_BACKSLASH_ESCAPES mode.
        return $this->dialect instanceof MySqlDialect ? "X'".bin2hex($value)."'"
            : "'".str_replace("'", "''", $value)."'";
    }

    private function quoteColumn(string $name): string
    {
        return $this->dialect instanceof MySqlDialect
            ? '`'.str_replace('`', '``', $name).'`'
            : '"'.str_replace('"', '""', $name).'"';
    }
}
