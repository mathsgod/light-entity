<?php

declare(strict_types=1);

namespace PhpEntity;

use PDO;
use PhpLinq\Queryable;
use PhpLinq\Sql\SqlDialect;

class DbContext
{
    public readonly DatabaseFacade $database;
    public readonly IModel $model;
    private \SplObjectStorage $entries;
    private SqlQueryProvider $provider;
    private SqlDialect $dialect;

    /** @param iterable<EntityMapping> $mappings */
    public function __construct(private PDO $pdo, ?SqlDialect $dialect = null, iterable $mappings = [])
    {
        $this->dialect = $dialect ?? match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => new \PhpLinq\Sql\SqliteDialect(),
            'mysql' => new \PhpLinq\Sql\MySqlDialect(),
            default => throw new \LogicException('Specify a dialect for this database driver.'),
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->database = new DatabaseFacade($pdo);
        $this->entries = new \SplObjectStorage();
        $this->model = new Model(new \PhpEntity\Schema\SchemaReader($pdo), $mappings);
        $this->provider = new SqlQueryProvider($pdo, $this->dialect);
    }

    /** @template T of object @param class-string<T> $class @return DbSet<T> */
    public function set(string $class, ?string $table = null): DbSet
    {
        $mapping = $this->model->getEntityType($class, $table);
        return new DbSet($this, $mapping, Queryable::from($this->provider, $mapping->table));
    }

    public function add(string $class, object $entity): void
    {
        $mapping = $this->model->getEntityType($class);
        $values = $mapping->values($entity);
        if ($this->entries->offsetExists($entity)) {
            throw new \LogicException('Entity is already tracked.');
        }
        if ($mapping->generatedKey && $values[$mapping->key] !== null) {
            throw new \LogicException('A generated key must be null when adding an entity.');
        }
        if (!$mapping->generatedKey && $values[$mapping->key] === null) {
            throw new \LogicException('A non-generated key is required.');
        }
        $this->assertIdentityAvailable($mapping, $values[$mapping->key], $entity);
        $this->entries[$entity] = [$mapping, 'added', $values];
    }

    public function remove(string $class, object $entity): void
    {
        $mapping = $this->model->getEntityType($class);
        $values = $mapping->values($entity);
        if ($this->entries->offsetExists($entity)) {
            [$existing, $state, $original] = $this->entries[$entity];
            if ($existing !== $mapping) {
                throw new \LogicException('Entity is tracked with a different mapping.');
            }
            if ($state === 'added') {
                $this->entries->offsetUnset($entity);
                return;
            }
        } else {
            $original = $values;
            $this->assertIdentityAvailable($mapping, $values[$mapping->key], $entity);
        }
        if ($original[$mapping->key] === null) {
            throw new \LogicException('Cannot remove an entity without a key.');
        }
        $this->entries[$entity] = [$mapping, 'deleted', $original];
    }

    /** @internal */
    public function tracked(EntityMapping $mapping, int|string $key): ?object
    {
        foreach ($this->entries as $entity) {
            [$entryMapping, , $original] = $this->entries[$entity];
            if ($entryMapping === $mapping && (string) $original[$mapping->key] === (string) $key) {
                return $entity;
            }
        }
        return null;
    }

    /** @internal */
    public function materialize(EntityMapping $mapping, array $row): object
    {
        $key = $row[$mapping->key] ?? throw new \LogicException('Query returned no entity key.');
        if (($entity = $this->tracked($mapping, $key)) !== null) {
            return $entity;
        }
        $entity = $mapping->hydrate($row);
        $this->entries[$entity] = [$mapping, 'unchanged', $mapping->values($entity)];
        return $entity;
    }

    /** Returns the number of entity write operations saved (outer transactions commit separately). */
    public function saveChanges(): int
    {
        $accepted = [];
        $count = 0;
        $savepoint = $this->database->beginSaveChanges();
        try {
            foreach ($this->entries as $entity) {
                [$mapping, $state, $original] = $this->entries[$entity];
                $values = $mapping->values($entity);
                $key = $mapping->key;
                if ($values[$key] !== $original[$key]) {
                    throw new \LogicException('Changing a tracked primary key is not supported.');
                }
                $table = $this->quote($mapping->table);
                if ($state === 'added') {
                    $insert = $mapping->insertValues($entity);
                    if ($insert === []) {
                        $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                            ? "INSERT INTO {$table} () VALUES ()" : "INSERT INTO {$table} DEFAULT VALUES";
                        $this->execute($sql, []);
                    } else {
                        $columns = implode(', ', array_map($this->quote(...), array_keys($insert)));
                        $placeholders = implode(', ', array_fill(0, count($insert), '?'));
                        $this->execute("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})", array_values($insert));
                    }
                    if ($mapping->generatedKey) {
                        $id = $this->pdo->lastInsertId();
                        if ($id === false || $id === '') {
                            throw new \RuntimeException('Driver did not return a generated key.');
                        }
                        $values[$key] = $id;
                    }
                    $values = $this->reloadValues($mapping, $values[$key]);
                    ++$count;
                } elseif ($state === 'deleted') {
                    $this->execute("DELETE FROM {$table} WHERE ".$this->quote($key).' = ?', [$original[$key]]);
                    ++$count;
                } else {
                    $changes = [];
                    foreach ($values as $name => $value) {
                        if (($mapping->columns[$name] ?? null)?->computed && $value !== $original[$name]) {
                            throw new \LogicException("Computed columns cannot be modified.");
                        }
                        if ($name !== $key && $value !== $original[$name]) {
                            $changes[$name] = $value;
                        }
                    }
                    if ($changes !== []) {
                        $assignments = implode(', ', array_map(fn ($name) => $this->quote($name).' = ?', array_keys($changes)));
                        $this->execute("UPDATE {$table} SET {$assignments} WHERE ".$this->quote($key).' = ?', [...array_values($changes), $original[$key]]);
                        $values = $this->reloadValues($mapping, $original[$key]);
                        ++$count;
                    }
                }
                $accepted[] = [$entity, $mapping, $state, $values];
            }
            $this->database->completeSaveChanges($savepoint);
        } catch (\Throwable $error) {
            $this->database->rollbackSaveChanges($savepoint);
            throw $error;
        }
        foreach ($accepted as [$entity, $mapping, $state, $values]) {
            if ($state === 'deleted') {
                $this->entries->offsetUnset($entity);
            } else {
                foreach ($values as $property => $value) {
                    $entity->$property = $value;
                }
                $this->entries[$entity] = [$mapping, 'unchanged', $values];
            }
        }
        return $count;
    }

    private function reloadValues(EntityMapping $mapping, mixed $key): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM '.$this->quote($mapping->table)
            .' WHERE '.$this->quote($mapping->key).' = ?');
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Written entity could not be reloaded.');
        }
        // Validate hydration before commit; apply database defaults/computed values afterwards.
        return $mapping->values($mapping->hydrate($row));
    }

    private function assertIdentityAvailable(EntityMapping $mapping, mixed $key, object $entity): void
    {
        if ($key !== null && ($tracked = $this->tracked($mapping, $key)) !== null && $tracked !== $entity) {
            throw new \LogicException('Another entity with this key is already tracked.');
        }
    }

    private function quote(string $name): string { return $this->dialect->quoteIdentifier($name); }

    private function execute(string $sql, array $values): void
    {
        $this->database->executeSql($sql, $values);
    }
}
