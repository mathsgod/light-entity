<?php

declare(strict_types=1);

namespace PhpEntity;

/** Owns identity tracking, snapshots, change detection and validated state transitions. */
final class ChangeTracker
{
    public QueryTrackingBehavior $queryTrackingBehavior = QueryTrackingBehavior::TrackAll;
    /** @var \SplObjectStorage<object, array{mapping:EntityMapping,state:EntityState,original:array,forced:bool}> */
    private \SplObjectStorage $records;
    /** Retain metadata for detached dynamic entities without retaining the objects. */
    private \WeakMap $knownMappings;

    public function __construct(private readonly DbContext $context)
    {
        $this->records = new \SplObjectStorage();
        $this->knownMappings = new \WeakMap();
    }

    /** @internal */
    public function entry(EntityMapping $mapping, object $entity): EntityEntry
    {
        $this->assertMapping($mapping, $entity);
        $this->knownMappings[$entity] = $mapping;
        return new EntityEntry($entity, $this->context, $mapping, $this);
    }

    /** Returns a stable list so callers can detach or change entries while iterating. */
    public function entries(?string $class = null): array
    {
        $this->detectChanges();
        $entries = [];
        foreach ($this->records as $entity) {
            if ($class === null || $entity instanceof $class) {
                $entries[] = $this->entry($this->records[$entity]['mapping'], $entity);
            }
        }
        return $entries;
    }

    public function detectChanges(): void
    {
        foreach ($this->records as $entity) {
            $record = $this->records[$entity];
            $record['state'] = $this->state($entity);
            $this->records[$entity] = $record;
        }
    }

    /** @internal Metadata only: no identity record, snapshot or tracked state is created. */
    public function rememberMapping(EntityMapping $mapping, object $entity): void
    {
        $this->assertMapping($mapping, $entity);
        $this->knownMappings[$entity] = $mapping;
    }

    /** @internal */
    public function mapping(object $entity): ?EntityMapping
    {
        return $this->records->offsetExists($entity) ? $this->records[$entity]['mapping'] : ($this->knownMappings[$entity] ?? null);
    }

    /** @internal */
    public function state(object $entity): EntityState
    {
        if (!$this->records->offsetExists($entity)) { return EntityState::Detached; }
        $record = $this->records[$entity];
        if (in_array($record['state'], [EntityState::Added, EntityState::Deleted], true)) {
            return $record['state'];
        }
        return $record['forced'] || $record['mapping']->values($entity) !== $record['original']
            ? EntityState::Modified : EntityState::Unchanged;
    }

    /** @internal */
    public function originalValues(object $entity, EntityMapping $mapping): array
    {
        return $this->records->offsetExists($entity)
            ? $this->records[$entity]['original'] : $mapping->values($entity);
    }

    /** @internal */
    public function setState(EntityMapping $mapping, object $entity, EntityState $state): void
    {
        $this->assertMapping($mapping, $entity);
        if ($state === EntityState::Detached) {
            if ($this->records->offsetExists($entity)) { $this->records->offsetUnset($entity); }
            return;
        }
        $record = $this->records->offsetExists($entity) ? $this->records[$entity] : null;
        if ($record !== null && $record['state'] === EntityState::Added && $state === EntityState::Deleted) {
            $this->records->offsetUnset($entity);
            return;
        }
        $values = $mapping->values($entity);
        if ($record !== null) { $this->validateKey($mapping, $values, $record['original']); }
        if ($state === EntityState::Added) {
            foreach ($mapping->keys as $key) {
                if ($key === $mapping->generatedKeyProperty) {
                    if ($values[$key] !== null) { throw new \LogicException('A generated key must be null when adding an entity.'); }
                } elseif ($values[$key] === null) {
                    throw new \LogicException('A non-generated key is required.');
                }
            }
        } else {
            $mapping->keyValues($values);
        }
        $this->assertIdentityAvailable($mapping, $values, $entity);
        $this->knownMappings[$entity] = $mapping;
        $this->records[$entity] = [
            'mapping' => $mapping, 'state' => $state,
            'original' => $state === EntityState::Unchanged ? $values : ($record['original'] ?? $values),
            'forced' => $state === EntityState::Modified,
        ];
        if ($entity instanceof Entity) { $entity->attachRelations($this->context, $mapping); }
    }

    /** @internal */
    public function add(EntityMapping $mapping, object $entity): void
    {
        if ($this->records->offsetExists($entity)) { throw new \LogicException('Entity is already tracked.'); }
        $this->setState($mapping, $entity, EntityState::Added);
    }

    /** @internal */
    public function tracked(EntityMapping $mapping, int|string|array $key): ?object
    {
        $key = $mapping->normalizeKey($key);
        foreach ($this->records as $entity) {
            $record = $this->records[$entity];
            if ($record['mapping'] !== $mapping) { continue; }
            foreach ($key as $name => $value) {
                if ($record['original'][$name] === null || (string) $record['original'][$name] !== (string) $value) {
                    continue 2;
                }
            }
            return $entity;
        }
        return null;
    }

    /** @internal Current values to write for a tracked update; validates protected fields. */
    public function modifiedValues(EntityEntry $entry): array
    {
        $mapping = $entry->metadata;
        $values = $entry->currentValues;
        $original = $entry->originalValues;
        $this->validateKey($mapping, $values, $original);
        $changes = [];
        $forced = $this->records[$entry->entity]['forced'];
        foreach ($values as $name => $value) {
            $computed = ($mapping->columns[$name] ?? null)?->computed;
            if ($computed && $value !== $original[$name]) {
                throw new \LogicException('Computed columns cannot be modified.');
            }
            if (!$computed && !in_array($name, $mapping->keys, true) && ($forced || $value !== $original[$name])) {
                $changes[$name] = $value;
            }
        }
        return $changes;
    }

    /** @internal Validate keys before executing any write. */
    public function validateKey(EntityMapping $mapping, array $values, array $original): void
    {
        foreach ($mapping->keys as $key) {
            if ($values[$key] !== $original[$key]) {
                throw new \LogicException('Changing a tracked primary key is not supported.');
            }
        }
    }

    /** @internal Apply only after the save transaction/savepoint succeeds. */
    public function accept(array $accepted): void
    {
        foreach ($accepted as [$entry, $state, $values]) {
            if ($state === EntityState::Deleted) {
                $this->records->offsetUnset($entry->entity);
            } else {
                foreach ($values as $property => $value) { $entry->entity->$property = $value; }
                $this->records[$entry->entity] = [
                    'mapping' => $entry->metadata, 'state' => EntityState::Unchanged,
                    'original' => $values, 'forced' => false,
                ];
            }
        }
    }

    private function assertMapping(EntityMapping $mapping, object $entity): void
    {
        if (!$entity instanceof $mapping->class) { throw new \InvalidArgumentException('Entity does not match mapping.'); }
        if (($existing = $this->mapping($entity)) !== null && $existing !== $mapping) {
            throw new \LogicException('Entity is tracked with a different mapping.');
        }
    }

    private function assertIdentityAvailable(EntityMapping $mapping, array $values, object $entity): void
    {
        foreach ($mapping->keys as $key) { if ($values[$key] === null) { return; } }
        if (($tracked = $this->tracked($mapping, $mapping->keyValues($values))) !== null && $tracked !== $entity) {
            throw new \LogicException('Another entity with this key is already tracked.');
        }
    }
}
