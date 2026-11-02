<?php

declare(strict_types=1);

namespace PhpEntity\Schema;

use PDO;

final readonly class SchemaReader
{
    public function __construct(private PDO $pdo) {}

    /** @return non-empty-list<Column> */
    public function columns(string $table): array
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $columns = match ($driver) {
            'sqlite' => $this->sqlite($table),
            'mysql' => $this->mysql($table),
            default => throw new \LogicException("Schema discovery is not supported for {$driver}."),
        };
        if ($columns === []) {
            throw new \InvalidArgumentException("Table not found or inaccessible: {$table}");
        }
        if (count(array_filter($columns, fn (Column $c) => $c->primary)) === 0) {
            throw new \LogicException('Schema mapping requires at least one primary-key column.');
        }
        return $columns;
    }

    private function sqlite(string $table): array
    {
        $parts = explode('.', $table);
        if (count($parts) > 2) {
            throw new \InvalidArgumentException('Expected table or schema.table.');
        }
        $name = array_pop($parts);
        $schema = $parts[0] ?? 'main';
        $quote = fn (string $s) => '"'.str_replace('"', '""', $s).'"';
        $statement = $this->pdo->prepare('SELECT sql FROM '.$quote($schema).'.sqlite_master WHERE type = ? AND name = ?');
        $statement->execute(['table', $name]);
        $ddl = $statement->fetchColumn();
        if ($ddl === false) {
            return [];
        }
        $rows = $this->pdo->query('PRAGMA '.$quote($schema).'.table_xinfo('.$quote($name).')')->fetchAll(PDO::FETCH_ASSOC);
        // INTEGER PRIMARY KEY aliases rowid unless WITHOUT ROWID or backed by a PK index (e.g. DESC).
        $indexes = $this->pdo->query('PRAGMA '.$quote($schema).'.index_list('.$quote($name).')')->fetchAll(PDO::FETCH_ASSOC);
        $indexedKey = array_filter($indexes, fn ($r) => $r['origin'] === 'pk') !== [];
        return array_map(fn ($r) => new Column(
            $r['name'], $r['type'], !(bool) $r['notnull'] && !(bool) $r['pk'],
            (bool) $r['pk'], (bool) $r['pk'] && strtoupper(trim($r['type'])) === 'INTEGER'
                && !$indexedKey && !preg_match('/\bWITHOUT\s+ROWID\b/i', $ddl),
            (int) $r['hidden'] !== 0, $r['dflt_value'],
        ), $rows);
    }

    private function mysql(string $table): array
    {
        $parts = explode('.', $table);
        if (count($parts) > 2) {
            throw new \InvalidArgumentException('Expected table or schema.table.');
        }
        $name = array_pop($parts);
        $schema = $parts[0] ?? $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $statement = $this->pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, COLUMN_DEFAULT, GENERATION_EXPRESSION FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $statement->execute([$schema, $name]);
        return array_map(fn ($r) => new Column(
            $r['COLUMN_NAME'], $r['COLUMN_TYPE'], $r['IS_NULLABLE'] === 'YES',
            $r['COLUMN_KEY'] === 'PRI', str_contains(strtolower($r['EXTRA']), 'auto_increment'),
            ($r['GENERATION_EXPRESSION'] ?? '') !== '', $r['COLUMN_DEFAULT'],
        ), $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
