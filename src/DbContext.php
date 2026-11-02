<?php

declare(strict_types=1);

namespace PhpEntity;

use PDO;
use PhpLinq\Queryable;
use PhpEntity\Sql\SqlDialect;

class DbContext
{
    public readonly DatabaseFacade $database;
    public readonly IModel $model;
    public readonly ChangeTracker $changeTracker;
    private SaveChangesExecutor $saveExecutor;
    private SqlQueryProvider $provider;
    private RelationshipResolver $relationships;
    private IncludeLoader $includeLoader;

    /** @param iterable<EntityMapping> $mappings @param iterable<SaveChangesInterceptor> $interceptors */
    public function __construct(PDO $pdo, ?SqlDialect $dialect = null, iterable $mappings = [], iterable $interceptors = [])
    {
        $dialect ??= match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => new \PhpEntity\Sql\SqliteDialect(),
            'mysql' => new \PhpEntity\Sql\MySqlDialect(),
            default => throw new \LogicException('Specify a dialect for this database driver.'),
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->database = new DatabaseFacade($pdo);
        $this->changeTracker = new ChangeTracker($this);
        $this->model = new Model(new \PhpEntity\Schema\SchemaReader($pdo), $mappings);
        $this->provider = new SqlQueryProvider($pdo, $dialect);
        $this->relationships = new RelationshipResolver(new \PhpEntity\Schema\SchemaReader($pdo), $this->model);
        $this->includeLoader = new IncludeLoader($this, $pdo, $dialect, $this->relationships);
        $this->saveExecutor = new SaveChangesExecutor($this, $this->changeTracker, $this->database, $dialect, fn () => $this->includeLoader->clear());
        foreach ($interceptors as $interceptor) { $this->addInterceptors($interceptor); }
    }

    /** @template T of object @param class-string<T> $class @return DbSet<T> */
    public function set(string $class, ?string $table = null): DbSet
    {
        $mapping = $this->model->getEntityType($class, $table);
        return new DbSet($this, $mapping, Queryable::from($this->provider, $mapping->table));
    }

    /** Override a getter using an exact target table and FK column(s). */
    public function relationship(string $class, string $name, string $table, array $foreignColumns, ?string $targetClass = null): void
    {
        $this->relationships->configure($class, $name, $table, $foreignColumns, $targetClass);
        $this->includeLoader->clear();
    }

    /** @internal */
    public function related(Entity $entity, EntityMapping $source, string $name, QueryTrackingBehavior $tracking = QueryTrackingBehavior::TrackAll): mixed
    {
        $relation = $this->relationships->resolve($source, $name);
        $mapping = $relation['mapping'];
        $cached = $this->includeLoader->cached($entity, $name, $relation, $tracking);
        if ($cached !== null) {
            return $relation['many']
                ? new DbSet($this, $mapping, $this->relationshipQuery($entity, $relation), false, [], $cached, $tracking)
                : ($cached[0] ?? null);
        }
        $set = new DbSet($this, $mapping, Queryable::from($this->provider, $mapping->table), trackingBehavior: $tracking);
        foreach ($relation['local'] as $i => $column) {
            $value = $entity->$column ?? null;
            if ($value === null && !$relation['many']) { return null; }
            $set = $set->where(\PhpLinq\Expr::eq(\PhpLinq\Expr::field($relation['remote'][$i]), $value));
        }
        // A missing key on an unsaved entity must never match unrelated nullable FKs.
        foreach ($relation['local'] as $column) {
            if (($entity->$column ?? null) === null) { return $set->take(0); }
        }
        return $relation['many'] ? $set : ($set->take(1)->toArray()[0] ?? null);
    }

    /** @internal */
    public function validateIncludes(EntityMapping $mapping, array $paths): void
    {
        $this->includeLoader->validate($mapping, $paths);
    }

    /** @internal */
    public function loadIncludes(EntityMapping $mapping, array $entities, array $paths, QueryTrackingBehavior $tracking = QueryTrackingBehavior::TrackAll): void
    {
        $this->includeLoader->load($mapping, $entities, $paths, $tracking);
    }

    private function relationshipQuery(object $entity, array $relation): Queryable
    {
        $query = Queryable::from($this->provider, $relation['mapping']->table);
        foreach ($relation['local'] as $i => $column) {
            $value = $entity->$column ?? null;
            if ($value === null) { return $query->take(0); }
            $query = $query->where(\PhpLinq\Expr::eq(\PhpLinq\Expr::field($relation['remote'][$i]), $value));
        }
        return $query;
    }

    public function entry(object $entity): EntityEntry
    {
        $mapping = $this->changeTracker->mapping($entity) ?? $this->model->getEntityType($entity::class);
        return $this->changeTracker->entry($mapping, $entity);
    }

    public function addInterceptors(SaveChangesInterceptor ...$interceptors): void
    {
        $this->saveExecutor->addInterceptors(...$interceptors);
    }

    public function add(string $class, object $entity): void
    {
        $this->changeTracker->add($this->model->getEntityType($class), $entity);
    }

    public function remove(string $class, object $entity): void
    {
        $mapping = $class === DynamicEntity::class
            ? ($this->changeTracker->mapping($entity) ?? $this->model->getEntityType($class))
            : $this->model->getEntityType($class);
        $this->changeTracker->setState($mapping, $entity, EntityState::Deleted);
    }

    /** @internal */
    public function tracked(EntityMapping $mapping, int|string|array $key): ?object
    {
        return $this->changeTracker->tracked($mapping, $key);
    }

    /** @internal */
    public function materialize(EntityMapping $mapping, array $row, QueryTrackingBehavior $tracking = QueryTrackingBehavior::TrackAll): object
    {
        if ($tracking === QueryTrackingBehavior::NoTracking) {
            $entity = $mapping->hydrate($row);
            if ($entity instanceof Entity) { $entity->attachRelations($this, $mapping, $tracking); }
            if ($entity instanceof DynamicEntity) { $this->changeTracker->rememberMapping($mapping, $entity); }
            return $entity;
        }
        if (($entity = $this->tracked($mapping, $mapping->keyValues($row))) !== null) { return $entity; }
        $entity = $mapping->hydrate($row);
        $this->changeTracker->setState($mapping, $entity, EntityState::Unchanged);
        return $entity;
    }

    /** Returns entity write operations saved; an outer transaction commits separately. */
    public function saveChanges(): int
    {
        return $this->saveExecutor->save();
    }
}
