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

    /** @return list<string> */
    public function generate(MigrationBuilder $migration): array
    {
        $statements = [];
        foreach ($migration->getOperations() as $operation) {
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
            $columns = [];
            foreach ($operation->columns as $column) {
                // A column name is one identifier; dots must not become qualifiers.
                $name = $this->quoteColumn($column->name);
                $type = $column->type === 'integer'
                    ? ($this->dialect instanceof SqliteDialect ? 'INTEGER' : 'INT')
                    : 'VARCHAR('.$column->length.')';
                $sql = $name.' '.$type.($column->nullable ? ' NULL' : ' NOT NULL');
                if ($column->primaryKey && !$composite) {
                    $sql .= ' PRIMARY KEY';
                }
                if ($column->autoIncrement) {
                    $sql .= $this->dialect instanceof MySqlDialect ? ' AUTO_INCREMENT' : ' AUTOINCREMENT';
                } elseif (!$composite && $column->primaryKey && $column->type === 'integer' && $this->dialect instanceof SqliteDialect) {
                    // Inline "INTEGER PRIMARY KEY" implies rowid-generated IDs in SQLite.
                    // INT retains integer affinity without being a rowid alias.
                    $sql = $name.' INT NOT NULL PRIMARY KEY';
                }
                $columns[] = $sql;
            }
            if ($composite) {
                $columns[] = 'PRIMARY KEY ('.implode(', ', array_map(fn ($key) => $this->quoteColumn($key->name), $keys)).')';
            }
            $statements[] = 'CREATE TABLE '.$this->dialect->quoteIdentifier($operation->table)
                ." (\n    ".implode(",\n    ", $columns)."\n)";
        }
        return $statements;
    }

    private function quoteColumn(string $name): string
    {
        return $this->dialect instanceof MySqlDialect
            ? '`'.str_replace('`', '``', $name).'`'
            : '"'.str_replace('"', '""', $name).'"';
    }
}
