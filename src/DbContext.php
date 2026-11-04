<?php

declare(strict_types=1);

namespace Light\Entity;

use PDO;
use Light\Linq\Queryable;
use Light\Entity\Sql\SqlDialect;

class DbContext
{
    public readonly DatabaseFacade $database;
    public readonly IModel $model;
    public readonly ChangeTracker $changeTracker;
    private SaveChangesExecutor $saveExecutor;
    private SqlQueryProvider $provider;
    private SqlFunctionRegistry $functions;
    private RelationshipResolver $relationships;
    private IncludeLoader $includeLoader;
    private ?ModelBuilder $modelBuilder = null;
    private bool $buildingModel = false;

    /** @param iterable<EntityMapping> $mappings @param iterable<SaveChangesInterceptor> $interceptors */
    public function __construct(PDO $pdo, ?SqlDialect $dialect = null, iterable $mappings = [], iterable $interceptors = [])
    {
        $dialect ??= match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => new \Light\Entity\Sql\SqliteDialect(),
            'mysql' => new \Light\Entity\Sql\MySqlDialect(),
            default => throw new \LogicException('Specify a dialect for this database driver.'),
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->database = new DatabaseFacade($pdo);
        $this->functions = new SqlFunctionRegistry();
        $this->changeTracker = new ChangeTracker($this);
        $this->model = new Model(new \Light\Entity\Schema\SchemaReader($pdo), $mappings);
        $this->provider = new SqlQueryProvider($pdo, $dialect, fn ($expression) => $this->prepareQuery($expression));
        $this->relationships = new RelationshipResolver(new \Light\Entity\Schema\SchemaReader($pdo), $this->model);
        $this->includeLoader = new IncludeLoader($this, $pdo, $dialect, $this->relationships);
        $this->saveExecutor = new SaveChangesExecutor($this, $this->changeTracker, $this->database, $dialect, fn () => $this->includeLoader->clear());
        foreach ($interceptors as $interceptor) { $this->addInterceptors($interceptor); }
    }

    /** @template T of object @param class-string<T> $class @return DbSet<T> */
    public function set(string $class, ?string $table = null): DbSet
    {
        $this->ensureModelCreated();
        $mapping = $this->model->getEntityType($class, $table);
        return new DbSet($this, $mapping, $this->entityQuery($mapping));
    }

    /** Runs lazily after the derived context has finished construction. */
    protected function onModelCreating(ModelBuilder $builder): void {}

    private function ensureModelCreated(): void
    {
        if ($this->modelBuilder !== null) { return; }
        if ($this->buildingModel) { throw new \LogicException('Do not execute queries inside onModelCreating().'); }
        $this->buildingModel = true;
        try {
            $builder = new ModelBuilder();
            $this->onModelCreating($builder);
            $builder->seal();
            $this->modelBuilder = $builder;
        } finally { $this->buildingModel = false; }
    }

    /** @internal */
    public function hasQueryFilters(EntityMapping $mapping): bool
    {
        $this->ensureModelCreated();
        return $this->modelBuilder->filters($mapping->class) !== [];
    }

    /** @internal */
    public function entityQuery(EntityMapping $mapping): Queryable
    {
        $source = new \Light\Linq\Expression\SourceExpression($mapping->table);
        return new Queryable($this->provider, $this->querySource($mapping, $source));
    }

    /** @internal */
    public function querySource(EntityMapping $mapping, \Light\Linq\Expression\Expression $source): \Light\Linq\Expression\Expression
    {
        $this->ensureModelCreated();
        return $this->modelBuilder->hasFilters()
            ? new \Light\Entity\Sql\EntityQuerySourceExpression($mapping, $source) : $source;
    }

    private function prepareQuery(\Light\Linq\Expression\Expression $expression): \Light\Linq\Expression\Expression
    {
        $this->ensureModelCreated();
        if (!$this->modelBuilder->hasFilters()) { return $expression; }
        return QueryFilterRewriter::rewrite($expression, function (\Light\Entity\Sql\EntityQuerySourceExpression $source) {
            $query = new Queryable($this->provider, $source->source);
            foreach ($this->modelBuilder->filters($source->mapping->class) as $name => $filter) {
                if ($source->ignoredFilters === null || in_array($name, $source->ignoredFilters, true)) { continue; }
                $predicate = $filter instanceof \Closure ? $filter($this) : $filter;
                if (is_array($predicate)) { $predicate = WhereFilter::parse($source->mapping->convertFilter($predicate)); }
                elseif ($predicate instanceof \Light\Linq\Expression\ValueExpression) { $predicate = $source->mapping->convertPredicate($predicate); }
                else { throw new \LogicException('A query filter must return a condition map or ValueExpression.'); }
                $query = $query->where($predicate);
            }
            return $query->expression();
        });
    }

    /** Apply target-entity filters to IncludeLoader's collation-aware batch SQL. @internal */
    public function compileFilteredInclude(EntityMapping $mapping, string $sql, array $parameters, ?array $ignoredFilters): \Light\Entity\Sql\CompiledQuery
    {
        if (!$this->hasQueryFilters($mapping)) { return new \Light\Entity\Sql\CompiledQuery($sql, $parameters); }
        $source = $this->querySource($mapping, new \Light\Entity\Sql\RawSqlSourceExpression($sql, $parameters));
        return $this->provider->compile(QueryFilterRewriter::ignore($source, $ignoredFilters));
    }

    public function registerFunction(string $name, ?string $schema = null, ?int $parameterCount = null): void
    {
        $this->functions->register($name, $schema, $parameterCount);
    }

    public function function(string $name, \Light\Linq\Expression\ValueExpression|int|float|string|bool|null ...$arguments): \Light\Entity\Sql\FunctionExpression
    {
        return $this->functions->call($name, ...$arguments);
    }

    /** Override a getter using an exact target table and FK column(s). */
    public function relationship(string $class, string $name, string $table, array $foreignColumns, ?string $targetClass = null): void
    {
        $this->relationships->configure($class, $name, $table, $foreignColumns, $targetClass);
        $this->includeLoader->clear();
    }

    /** @internal */
    public function related(Entity $entity, EntityMapping $source, string $name, QueryTrackingBehavior $tracking = QueryTrackingBehavior::TrackAll, ?array $ignoredFilters = []): mixed
    {
        $relation = $this->relationships->resolve($source, $name);
        $mapping = $relation['mapping'];
        $cached = $this->includeLoader->cached($entity, $name, $relation, $tracking, $ignoredFilters);
        if ($cached !== null) {
            return $relation['many']
                ? new DbSet($this, $mapping, $this->relationshipQuery($entity, $relation, $ignoredFilters), false, [], $cached, $tracking)
                : ($cached[0] ?? null);
        }
        $set = new DbSet($this, $mapping, $this->entityQuery($mapping), trackingBehavior: $tracking);
        $set = $set->ignoreQueryFilters($ignoredFilters);
        foreach ($relation['local'] as $i => $column) {
            $value = $entity->$column ?? null;
            if ($value === null && !$relation['many']) { return null; }
            $set = $set->where(\Light\Linq\Expr::eq(\Light\Linq\Expr::field($relation['remote'][$i]), $value));
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
    public function loadIncludes(EntityMapping $mapping, array $entities, array $paths, QueryTrackingBehavior $tracking = QueryTrackingBehavior::TrackAll, ?array $ignoredFilters = []): void
    {
        foreach ($entities as $entity) {
            if ($entity instanceof Entity) { $entity->attachRelations($this, $mapping, $tracking, $ignoredFilters); }
        }
        $this->includeLoader->load($mapping, $entities, $paths, $tracking, $ignoredFilters);
    }

    private function relationshipQuery(object $entity, array $relation, ?array $ignoredFilters): Queryable
    {
        $base = $this->entityQuery($relation['mapping']);
        $query = new Queryable($this->provider, QueryFilterRewriter::ignore($base->expression(), $ignoredFilters));
        foreach ($relation['local'] as $i => $column) {
            $value = $entity->$column ?? null;
            if ($value === null) { return $query->take(0); }
            $query = $query->where(\Light\Linq\Expr::eq(\Light\Linq\Expr::field($relation['remote'][$i]), $value));
        }
        return $query;
    }

    public function entry(object $entity): EntityEntry
    {
        $mapping = $this->changeTracker->mapping($entity) ?? $this->model->getEntityType($entity::class);
        return $this->changeTracker->entry($mapping, $entity);
    }

    /** Attach an existing entity; optional originals are model values, not raw database values. */
    public function attach(object $entity, ?array $originalValues = null): EntityEntry
    {
        $mapping = $this->changeTracker->mapping($entity) ?? $this->model->getEntityType($entity::class);
        return $this->changeTracker->attach($mapping, $entity, $originalValues);
    }

    /** Marks all writable non-key properties for update on saveChanges(); requires a complete key. */
    public function update(object $entity): EntityEntry
    {
        $entry = $this->entry($entity);
        $entry->state = EntityState::Modified;
        return $entry;
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
        if (($entity = $this->tracked($mapping, $mapping->keyValues($row))) !== null) {
            $mapping->validateRow($row);
            return $entity;
        }
        $entity = $mapping->hydrate($row);
        $this->changeTracker->setState($mapping, $entity, EntityState::Unchanged);
        return $entity;
    }

    /** @internal Executes immediately without updating tracked entities or snapshots. */
    public function executeModification(EntityMapping $mapping, Queryable $query, ?array $values = null): int
    {
        if ($query->provider() !== $this->provider) {
            throw new \LogicException('Bulk writes require the context query provider.');
        }
        foreach ($values ?? [] as $name => $value) {
            if (!in_array($name, $mapping->properties, true)) {
                throw new \InvalidArgumentException('Unknown update property: '.(string) $name);
            }
            if (in_array($name, $mapping->keys, true) || ($mapping->columns[$name] ?? null)?->computed) {
                throw new \InvalidArgumentException('Bulk updates cannot assign primary keys or computed columns.');
            }
        }
        if ($values !== null) {
            foreach ($values as $name => $value) {
                // SQL expressions already represent provider-side values.
                if (!$value instanceof \Light\Linq\Expression\ValueExpression) {
                    $values[$name] = $mapping->toProviderValue($name, $value);
                }
            }
        }
        return $this->provider->executeModification($query->expression(), $values);
    }

    /** @return \Generator<int, object> @internal */
    public function streamNoTrackingEntities(EntityMapping $mapping, Queryable $query): \Generator
    {
        if ($query->provider() !== $this->provider) {
            throw new \LogicException('Streaming requires the context query provider.');
        }
        foreach ($this->provider->streamRows($query->expression()) as $row) {
            $entity = $this->materialize($mapping, $row, QueryTrackingBehavior::NoTracking);
            if ($entity instanceof Entity) { $entity->attachRelations($this, $mapping, QueryTrackingBehavior::NoTracking, QueryFilterRewriter::ignored($query->expression())); }
            yield $entity;
        }
    }

    /** @return list<object>|null @internal */
    public function fetchNoTrackingEntities(EntityMapping $mapping, Queryable $query): ?array
    {
        if ($query->provider() !== $this->provider) {
            return null;
        }
        $entities = $this->provider->executeNoTrackingEntities($query->expression(), $mapping);
        if ($entities === null) {
            return null;
        }
        foreach ($entities as $entity) {
            if ($entity instanceof Entity) { $entity->attachRelations($this, $mapping, QueryTrackingBehavior::NoTracking, QueryFilterRewriter::ignored($query->expression())); }
            if ($entity instanceof DynamicEntity) { $this->changeTracker->rememberMapping($mapping, $entity); }
        }
        return $entities;
    }

    /** Returns entity write operations saved; an outer transaction commits separately. */
    public function saveChanges(): int
    {
        return $this->saveExecutor->save();
    }
}
