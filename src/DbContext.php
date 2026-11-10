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
        $this->model = new Model(new \Light\Entity\Schema\SchemaReader($pdo), $mappings, $this->ensureModelCreated(...));
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
            $this->model->configureInheritance($builder->discriminators());
            $this->model->configureComplexProperties($builder->complexProperties());
            $this->modelBuilder = $builder;
        } finally { $this->buildingModel = false; }
    }

    /** @internal */
    public function hasQueryFilters(EntityMapping $mapping): bool
    {
        $this->ensureModelCreated();
        return $mapping->hierarchy !== null || $this->modelBuilder->filters($mapping->class) !== [];
    }

    /** @internal */
    public function entityQuery(EntityMapping $mapping): Queryable
    {
        $source = new \Light\Linq\Expressions\SourceExpression($mapping->table);
        return new Queryable($this->provider, $this->querySource($mapping, $source));
    }

    /** @internal */
    public function querySource(EntityMapping $mapping, \Light\Linq\Expressions\Expression $source): \Light\Linq\Expressions\Expression
    {
        $this->ensureModelCreated();
        return new \Light\Entity\Sql\EntityQuerySourceExpression($mapping, $source, filterResolver: $this->resolveQueryFilters(...));
    }

    private function prepareQuery(\Light\Linq\Expressions\Expression $expression): \Light\Linq\Expressions\Expression
    {
        $this->ensureModelCreated();
        $expression = ComplexQueryRewriter::rewrite($expression);
        return QueryFilterRewriter::rewrite($expression, fn (\Light\Entity\Sql\EntityQuerySourceExpression $source) =>
            ($source->filterResolver ?? $this->resolveQueryFilters(...))($source));
    }

    /** Each source resolves filters in its owning context, including cross-context subqueries. */
    private function resolveQueryFilters(\Light\Entity\Sql\EntityQuerySourceExpression $source): \Light\Linq\Expressions\Expression
    {
        $mapping = $source->mapping;
        $query = new Queryable($this->provider, $source->source);
        // Type predicates are structural and cannot be disabled with ignoreQueryFilters().
        if ($mapping->hierarchy !== null && $mapping->class !== $mapping->hierarchy->root) {
            $values = $mapping->hierarchy->valuesFor($mapping->class);
            $predicate = null;
            foreach ($values as $value) {
                $part = \Light\Linq\Expr::eq(\Light\Linq\Expr::field($mapping->hierarchy->column), $value);
                $predicate = $predicate === null ? $part : new \Light\Linq\Expressions\LogicalExpression($predicate, 'or', $part);
            }
            $query = $query->where($predicate ?? \Light\Linq\Expr::eq(\Light\Linq\Expr::value(1), 0));
        }
        foreach ($this->modelBuilder->filters($source->mapping->class) as $name => $filter) {
            if ($source->ignoredFilters === null || in_array($name, $source->ignoredFilters, true)) { continue; }
            $predicate = $filter instanceof \Closure ? $filter($this) : $filter;
            if (is_array($predicate)) { $predicate = WhereFilter::parse($source->mapping->convertFilter($predicate)); }
            elseif ($predicate instanceof \Light\Linq\Expressions\ValueExpression) { $predicate = $source->mapping->convertPredicate($predicate); }
            else { throw new \LogicException('A query filter must return a condition map or ValueExpression.'); }
            $query = $query->where(ComplexQueryRewriter::value($predicate, $mapping->complexPaths()));
        }
        return $query->expression();
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

    public function function(string $name, \Light\Linq\Expressions\ValueExpression|int|float|string|bool|null ...$arguments): \Light\Entity\Sql\FunctionExpression
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
            if ($entity instanceof Entity) { $entity->attachRelations($this, $this->entityMapping($mapping, $entity), $tracking, $ignoredFilters); }
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
        $this->ensureModelCreated();
        $mapping = $this->changeTracker->mapping($entity) ?? $this->model->getEntityType($entity::class);
        return $this->changeTracker->entry($mapping, $entity);
    }

    /** Attach an existing entity; optional originals are model values, not raw database values. */
    public function attach(object $entity, ?array $originalValues = null): EntityEntry
    {
        $this->ensureModelCreated();
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
        $this->ensureModelCreated();
        $mapping = $this->model->getEntityType($class);
        $this->changeTracker->add($this->entityMapping($mapping, $entity), $entity);
    }

    public function remove(string $class, object $entity): void
    {
        $this->ensureModelCreated();
        $mapping = $class === DynamicEntity::class
            ? ($this->changeTracker->mapping($entity) ?? $this->model->getEntityType($class))
            : $this->model->getEntityType($class);
        $this->changeTracker->setState($this->entityMapping($mapping, $entity), $entity, EntityState::Deleted);
    }

    /** @internal Resolve writes and relationships against the concrete entity metadata. */
    public function entityMapping(EntityMapping $mapping, object $entity): EntityMapping
    {
        if (!$entity instanceof $mapping->class) throw new \InvalidArgumentException('Entity does not match mapping.');
        return $mapping->hierarchy === null || $mapping->class === $entity::class
            ? $mapping : $this->model->getEntityType($entity::class);
    }

    /** @internal */
    public function tracked(EntityMapping $mapping, int|string|array $key): ?object
    {
        return $this->changeTracker->tracked($mapping, $key);
    }

    /** @internal */
    public function materialize(EntityMapping $mapping, array $row, QueryTrackingBehavior $tracking = QueryTrackingBehavior::TrackAll): object
    {
        $class = $mapping->concreteClass($row);
        if ($class !== $mapping->class) $mapping = $this->model->getEntityType($class);
        if ($tracking === QueryTrackingBehavior::NoTracking) {
            $entity = $mapping->hydrate($row);
            if ($entity instanceof Entity) { $entity->attachRelations($this, $this->entityMapping($mapping, $entity), $tracking); }
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
        if ($values !== null) {
            $bound = [];
            foreach ($values as $name => $value) {
                $column = $mapping->columnFor((string) $name);
                if (array_key_exists($column, $bound)) throw new \InvalidArgumentException('Duplicate update column: '.$column);
                $bound[$column] = $value instanceof \Light\Linq\Expressions\ValueExpression
                    ? ComplexQueryRewriter::value($value, $mapping->complexPaths()) : $value;
            }
            $values = $bound;
        }
        foreach ($values ?? [] as $name => $value) {
            if (!in_array($name, $mapping->properties, true)) {
                throw new \InvalidArgumentException('Unknown update property: '.(string) $name);
            }
            if ($name === $mapping->hierarchy?->column || in_array($name, $mapping->keys, true) || ($mapping->columns[$name] ?? null)?->computed) {
                throw new \InvalidArgumentException('Bulk updates cannot assign primary keys, computed columns or discriminators.');
            }
        }
        if ($values !== null) {
            foreach ($values as $name => $value) {
                // SQL expressions already represent provider-side values.
                if (!$value instanceof \Light\Linq\Expressions\ValueExpression) {
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
            if ($entity instanceof Entity) { $entity->attachRelations($this, $this->entityMapping($mapping, $entity), QueryTrackingBehavior::NoTracking, QueryFilterRewriter::ignored($query->expression())); }
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
            if ($entity instanceof Entity) { $entity->attachRelations($this, $this->entityMapping($mapping, $entity), QueryTrackingBehavior::NoTracking, QueryFilterRewriter::ignored($query->expression())); }
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
