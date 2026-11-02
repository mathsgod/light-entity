<?php

declare(strict_types=1);

namespace Light\Entity;

use PDO;
use Light\Entity\Sql\SqlDialect;

/** Coordinates save hooks, SQL writes, transaction boundaries and snapshot acceptance. */
final class SaveChangesExecutor
{
    /** @var list<SaveChangesInterceptor> */
    private array $interceptors = [];
    private bool $saving = false;

    public function __construct(
        private readonly DbContext $context,
        private readonly ChangeTracker $tracker,
        private readonly DatabaseFacade $database,
        private readonly SqlDialect $dialect,
        private readonly \Closure $invalidateRelationships,
    ) {}

    public function addInterceptors(SaveChangesInterceptor ...$interceptors): void
    {
        if ($this->saving) { throw new \LogicException('Cannot register interceptors during saveChanges().'); }
        array_push($this->interceptors, ...$interceptors);
    }

    public function save(): int
    {
        if ($this->saving) { throw new \LogicException('Recursive saveChanges() is not supported.'); }
        $this->saving = true;
        $started = false;
        $savepoint = null;
        try {
            try {
                $this->tracker->detectChanges();
                $savepoint = $this->database->beginSaveChanges();
                $started = true;
                foreach ($this->interceptors as $interceptor) { $interceptor->savingChanges($this->context); }
                $this->tracker->detectChanges();
                $plans = $this->plans();
                $accepted = [];
                $count = 0;
                foreach ($plans as [$entry, $state, $values, $changes]) {
                    $mapping = $entry->metadata;
                    if ($state === EntityState::Added) {
                        $values = $this->insert($entry, $values);
                        ++$count;
                    } elseif ($state === EntityState::Deleted) {
                        [$where, $parameters] = $this->keyWhere($mapping, $entry->originalValues);
                        $this->database->executeSql('DELETE FROM '.$this->quote($mapping->table).' WHERE '.$where, $parameters);
                        ++$count;
                    } elseif ($changes !== []) {
                        $assignments = implode(', ', array_map(fn ($name) => $this->quote($name).' = ?', array_keys($changes)));
                        [$where, $parameters] = $this->keyWhere($mapping, $entry->originalValues);
                        $this->database->executeSql('UPDATE '.$this->quote($mapping->table)." SET {$assignments} WHERE {$where}", [...array_values($changes), ...$parameters]);
                        $values = $this->reloadValues($mapping, $entry->originalValues);
                        ++$count;
                    }
                    $accepted[] = [$entry, $state, $values];
                }
                $this->database->completeSaveChanges($savepoint);
                $started = false;
            } catch (\Throwable $error) {
                if ($started) {
                    try { $this->database->rollbackSaveChanges($savepoint); }
                    catch (\Throwable) { /* Preserve the original save exception. */ }
                }
                foreach ($this->interceptors as $interceptor) {
                    try { $interceptor->saveChangesFailed($this->context, $error); }
                    catch (\Throwable) { /* Failure observers must not replace the save exception. */ }
                }
                throw $error;
            }
            // These steps occur after commit/release. Their exceptions must not be
            // reported as failed SQL saves or cause an attempted rollback.
            $this->tracker->accept($accepted);
            if ($count > 0) { ($this->invalidateRelationships)(); }
            foreach ($this->interceptors as $interceptor) { $interceptor->savedChanges($this->context, $count); }
            return $count;
        } finally {
            $this->saving = false;
        }
    }

    /** Freeze and validate all tracked writes after pre-save hooks have finished. */
    private function plans(): array
    {
        $plans = [];
        foreach ($this->tracker->entries() as $entry) {
            $state = $entry->state;
            $values = $entry->currentValues;
            $this->tracker->validateKey($entry->metadata, $values, $entry->originalValues);
            $changes = in_array($state, [EntityState::Unchanged, EntityState::Modified], true)
                ? $this->tracker->modifiedValues($entry) : [];
            $plans[] = [$entry, $state, $values, $changes];
        }
        return $plans;
    }

    private function insert(EntityEntry $entry, array $values): array
    {
        $mapping = $entry->metadata;
        $table = $this->quote($mapping->table);
        $insert = $mapping->insertValues($entry->entity);
        if ($insert === []) {
            $sql = $this->database->getDbConnection()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? "INSERT INTO {$table} () VALUES ()" : "INSERT INTO {$table} DEFAULT VALUES";
            $this->database->executeSql($sql);
        } else {
            $columns = implode(', ', array_map($this->quote(...), array_keys($insert)));
            $placeholders = implode(', ', array_fill(0, count($insert), '?'));
            $this->database->executeSql("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})", array_values($insert));
        }
        if ($mapping->generatedKey) {
            $id = $this->database->getDbConnection()->lastInsertId();
            if ($id === false || $id === '') { throw new \RuntimeException('Driver did not return a generated key.'); }
            $values[$mapping->generatedKeyProperty] = $id;
        }
        return $this->reloadValues($mapping, $values);
    }

    private function reloadValues(EntityMapping $mapping, array $values): array
    {
        [$where, $parameters] = $this->keyWhere($mapping, $values);
        $statement = $this->database->getDbConnection()->prepare('SELECT * FROM '.$this->quote($mapping->table).' WHERE '.$where);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) { throw new \RuntimeException('Written entity could not be reloaded.'); }
        // Validate hydration before commit; apply defaults/computed values afterwards.
        return $mapping->values($mapping->hydrate($row));
    }

    private function keyWhere(EntityMapping $mapping, array $values): array
    {
        $keys = $mapping->keyValues($values);
        $conditions = array_map(fn ($key) => $this->quote($key).' = ?', array_keys($keys));
        return [implode(' AND ', $conditions), array_values($keys)];
    }

    private function quote(string $name): string { return $this->dialect->quoteIdentifier($name); }
}
