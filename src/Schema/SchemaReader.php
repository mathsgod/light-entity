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
    /** Tables in the current database (or an explicitly qualified schema). */
    public function tables(?string $source = null): array
    {
        $schema = $source !== null && str_contains($source, '.') ? explode('.', $source)[0] : null;
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $prefix = $schema ?? 'main';
            $names = $this->pdo->query('SELECT name FROM "'.str_replace('"', '""', $prefix).'".sqlite_master WHERE type = \'table\' AND name NOT LIKE \'sqlite_%\'')->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $statement = $this->pdo->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = COALESCE(?, DATABASE()) AND TABLE_TYPE = \'BASE TABLE\'');
            $statement->execute([$schema]);
            $names = $statement->fetchAll(PDO::FETCH_COLUMN);
        }
        return array_map(fn ($name) => $schema === null ? $name : $schema.'.'.$name, $names);
    }

    /** @return list<array{table:string,local:array,remote:array,unique:bool}> */
    public function foreignKeys(string $table): array
    {
        $parts = explode('.', $table);
        $name = array_pop($parts);
        $schema = $parts[0] ?? null;
        $groups = [];
        $unique = [];
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $quote = fn ($s) => '"'.str_replace('"', '""', $s).'"';
            $pragma = 'PRAGMA '.$quote($schema ?? 'main').'.';
            $rows = $this->pdo->query($pragma.'foreign_key_list('.$quote($name).')')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($this->pdo->query($pragma.'index_list('.$quote($name).')')->fetchAll(PDO::FETCH_ASSOC) as $index) {
                if (!$index['unique'] || $index['partial']) { continue; }
                $unique[] = array_column($this->pdo->query($pragma.'index_info('.$quote($index['name']).')')->fetchAll(PDO::FETCH_ASSOC), 'name');
            }
            $primary = array_filter($this->pdo->query($pragma.'table_info('.$quote($name).')')->fetchAll(PDO::FETCH_ASSOC), fn ($r) => $r['pk'] > 0);
            usort($primary, fn ($a, $b) => $a['pk'] <=> $b['pk']);
            $unique[] = array_column($primary, 'name');
            foreach ($rows as $row) {
                $id = $row['id'];
                $target = ($schema === null ? '' : $schema.'.').$row['table'];
                $groups[$id]['table'] = $target;
                $groups[$id]['local'][(int) $row['seq']] = $row['from'];
                $remote = $row['to'];
                if ($remote === null || $remote === '') {
                    $keys = array_values(array_filter($this->pdo->query($pragma.'table_info('.$quote($row['table']).')')->fetchAll(PDO::FETCH_ASSOC), fn ($r) => $r['pk'] > 0));
                    usort($keys, fn ($a, $b) => $a['pk'] <=> $b['pk']);
                    $remote = $keys[(int) $row['seq']]['name'];
                }
                $groups[$id]['remote'][(int) $row['seq']] = $remote;
            }
        } else {
            $statement = $this->pdo->prepare('SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = COALESCE(?, DATABASE()) AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION');
            $statement->execute([$schema, $name]);
            $currentSchema = $schema ?? $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = $row['CONSTRAINT_NAME'];
                $groups[$id]['table'] = ($schema === null && $row['REFERENCED_TABLE_SCHEMA'] === $currentSchema ? '' : $row['REFERENCED_TABLE_SCHEMA'].'.').$row['REFERENCED_TABLE_NAME'];
                $groups[$id]['local'][] = $row['COLUMN_NAME'];
                $groups[$id]['remote'][] = $row['REFERENCED_COLUMN_NAME'];
            }
            $statement = $this->pdo->prepare('SELECT INDEX_NAME, COLUMN_NAME, SUB_PART FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = COALESCE(?, DATABASE()) AND TABLE_NAME = ? AND NON_UNIQUE = 0 ORDER BY INDEX_NAME, SEQ_IN_INDEX');
            $statement->execute([$schema, $name]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $unique[$row['INDEX_NAME']][] = $row['SUB_PART'] === null ? $row['COLUMN_NAME'] : null;
            }
        }
        foreach ($groups as &$group) {
            ksort($group['local']); ksort($group['remote']);
            $group['local'] = array_values($group['local']);
            $group['remote'] = array_values($group['remote']);
            $group['unique'] = false;
            foreach ($unique as $columns) {
                if ($columns !== [] && !in_array(null, $columns, true) && array_diff($columns, $group['local']) === []) { $group['unique'] = true; }
            }
        }
        return array_values($groups);
    }
}
