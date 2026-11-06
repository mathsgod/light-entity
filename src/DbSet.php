<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Linq\Expr;
use Light\Linq\Queryable;
use Light\Linq\{IQueryable, IQueryProvider, IEnumerable, Enumerable};
use Light\Linq\Expressions\Expression;
use Light\Linq\Expressions\ValueExpression;

/** @template T of object @implements IQueryable<T> */
final readonly class DbSet implements IQueryable, \Countable
{
    public function __construct(
        private DbContext $context,
        private EntityMapping $mapping,
        private Queryable $query,
        private bool $root = true,
        private array $includes = [],
        private ?array $loaded = null,
        private ?QueryTrackingBehavior $trackingBehavior = null,
    ) {}

    public function expression(): Expression
    {
        return $this->query->expression();
    }

    public function provider(): IQueryProvider
    {
        return $this->query->provider();
    }

    /** Start a composable SQL query on an unfiltered entity set. */
    public function fromSqlRaw(string $sql, array $parameters = []): self
    {
        if (!$this->root || !($this->query->expression() instanceof \Light\Linq\Expressions\SourceExpression || $this->query->expression() instanceof \Light\Entity\Sql\EntityQuerySourceExpression)) {
            throw new \LogicException('fromSqlRaw() requires the root DbSet; apply it before query operators.');
        }
        $source = $this->context->querySource($this->mapping, new \Light\Entity\Sql\RawSqlSourceExpression($sql, $parameters));
        $source = QueryFilterRewriter::ignore($source, QueryFilterRewriter::ignored($this->query->expression()));
        $query = new Queryable($this->query->provider(), $source);
        return new self($this->context, $this->mapping, $query, false, $this->includes, trackingBehavior: $this->trackingBehavior);
    }

    /** Eager-load a relationship or a dotted path when entities are materialized. */
    public function include(string $path): self
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $path)) {
            throw new \InvalidArgumentException('Include requires a non-empty relationship path.');
        }
        $paths = array_values(array_unique([...$this->includes, $path]));
        return new self($this->context, $this->mapping, $this->query, $this->root, $paths, $this->loaded, $this->trackingBehavior);
    }

    /** Disable all filters (null) or selected names for this query, without changing the context. */
    public function ignoreQueryFilters(?array $names = null): self
    {
        $query = new Queryable($this->query->provider(), QueryFilterRewriter::ignore($this->query->expression(), $names));
        return new self($this->context, $this->mapping, $query, $this->root, $this->includes, trackingBehavior: $this->trackingBehavior);
    }

    public function asNoTracking(): self
    {
        return $this->withTracking(QueryTrackingBehavior::NoTracking);
    }

    public function asTracking(): self
    {
        return $this->withTracking(QueryTrackingBehavior::TrackAll);
    }

    public function where(ValueExpression|array|\Closure $predicate): self
    {
        if ($predicate instanceof \Closure) {
            $predicate = ClosureQueryTranslator::translate($predicate, $this->mapping, $this->query->provider());
            return $this->with($this->query->where($predicate));
        }
        $predicate = is_array($predicate)
            ? WhereFilter::parse($this->mapping->convertFilter($predicate))
            : $this->mapping->convertPredicate($predicate);
        return $this->with($this->query->where($predicate));
    }

    /** Filter by membership in a single-column SQL subquery. */
    public function whereIn(string|ValueExpression $item, IQueryable $subquery): self
    {
        $this->validateSubqueryProvider($subquery);
        return $this->where(new \Light\Entity\Sql\InExpression(
            is_string($item) ? Expr::field($item) : $item, $subquery,
        ));
    }

    /** SQL NOT IN semantics apply, including NULL values in the subquery. */
    public function whereNotIn(string|ValueExpression $item, IQueryable $subquery): self
    {
        $this->validateSubqueryProvider($subquery);
        return $this->where(new \Light\Entity\Sql\InExpression(
            is_string($item) ? Expr::field($item) : $item, $subquery, true,
        ));
    }

    private function validateSubqueryProvider(IQueryable $subquery): void
    {
        $outer = $this->query->provider();
        $inner = $subquery->provider();
        if ($inner !== $outer && !($outer instanceof SqlQueryProvider && $inner instanceof SqlQueryProvider
            && $outer->sharesConnectionWith($inner))) {
            throw new \InvalidArgumentException('IN subqueries must use the same PDO connection as the outer query.');
        }
    }

    /** Append a trusted SQL condition with bound positional or named provider values. */
    public function whereRaw(string $sql, array $parameters = []): self
    {
        return $this->where(new \Light\Entity\Sql\RawSqlPredicateExpression($sql, $parameters));
    }

    /** Raw row query for composition; results are not tracked entities. */
    public function asQueryable(): Queryable
    {
        return $this->query;
    }

    /** Returns projected values without tracking them as entities. */
    public function groupBy(ValueExpression $keySelector): Queryable
    {
        return $this->query->groupBy($keySelector);
    }
    public function distinct(): Queryable
    {
        return $this->query->distinct();
    }
    public function select(ValueExpression|\Closure $selector): Queryable
    {
        return $this->query->select($selector);
    }

    /** Return untracked rows from the set operation. */
    public function concat(IQueryable $second): Queryable
    {
        return $this->setQuery('concat', $second);
    }

    /** Return untracked rows from the set operation. */
    public function union(IQueryable $second): Queryable
    {
        return $this->setQuery('union', $second);
    }

    /** Return untracked rows from the set operation. */
    public function intersect(IQueryable $second): Queryable
    {
        return $this->setQuery('intersect', $second);
    }

    /** Return untracked rows from the set operation. */
    public function except(IQueryable $second): Queryable
    {
        return $this->setQuery('except', $second);
    }

    /** Flatten another set into untracked projected rows or scalar values. */
    public function selectMany(
        IQueryable $inner,
        ValueExpression $resultSelector,
        ?ValueExpression $predicate = null,
    ): Queryable {
        return $this->query->selectMany($inner, $resultSelector, $predicate);
    }

    /** Result fields use outer.* and inner.*; keys are relative to each source. */
    public function join(
        IQueryable $inner,
        ValueExpression $outerKey,
        ValueExpression $innerKey,
        ValueExpression $resultSelector,
    ): Queryable
    {
        return $this->query->join($inner, $outerKey, $innerKey, $resultSelector);
    }

    public function leftJoin(
        IQueryable $inner,
        ValueExpression $outerKey,
        ValueExpression $innerKey,
        ValueExpression $resultSelector,
    ): Queryable
    {
        return $this->query->leftJoin($inner, $outerKey, $innerKey, $resultSelector);
    }

    /** SQL providers may reject this operator; use asEnumerable() for client-side grouping. */
    public function groupJoin(
        IQueryable $inner,
        ValueExpression $outerKey,
        ValueExpression $innerKey,
        ValueExpression $resultSelector,
    ): Queryable {
        return $this->query->groupJoin($inner, $outerKey, $innerKey, $resultSelector);
    }

    public function zip(IQueryable $second, ValueExpression $resultSelector): Queryable
    {
        return $this->query->zip($second, $resultSelector);
    }

    public function chunk(int $size): Queryable
    {
        return $this->query->chunk($size);
    }

    public function sum(ValueExpression $selector): int|float|string
    {
        return $this->aggregateQuery()->sum($selector);
    }

    public function average(ValueExpression $selector): int|float|string|null
    {
        return $this->aggregateQuery()->average($selector);
    }

    public function min(ValueExpression $selector): mixed
    {
        return $this->aggregateQuery()->min($selector);
    }

    public function max(ValueExpression $selector): mixed
    {
        return $this->aggregateQuery()->max($selector);
    }

    /** Entity membership is determined by all mapped primary-key values. */
    public function contains(mixed $value): bool
    {
        if (!$value instanceof $this->mapping->class) { return false; }
        $key = [];
        $predicate = null;
        foreach ($this->mapping->keys as $name) {
            $part = $value->$name ?? null;
            if ($part === null || !is_scalar($part)) { return false; }
            $key[$name] = $part;
            $comparison = Expr::eq(Expr::field($name), $part);
            $predicate = $predicate === null ? $comparison : Expr::both($predicate, $comparison);
        }
        if ($this->loaded !== null) {
            foreach ($this->loaded as $entity) {
                $matches = true;
                foreach ($key as $name => $part) { $matches = $matches && ($entity->$name ?? null) === $part; }
                if ($matches) { return true; }
            }
            return false;
        }
        // A terminal predicate preserves pagination and avoids materializing/tracking an entity.
        return $this->query->firstOrDefault($this->mapping->convertPredicate($predicate)) !== null;
    }

    /** Switch to client-side operations while preserving entity materialization. */
    public function asEnumerable(): IEnumerable
    {
        return Enumerable::from($this);
    }

    public function orderBy(ValueExpression $keySelector): self
    {
        return $this->with($this->query->orderBy($keySelector));
    }

    public function orderByDescending(ValueExpression $keySelector): self
    {
        return $this->with($this->query->orderByDescending($keySelector));
    }

    /** Start ordering by a trusted scalar SQL expression with bound provider values. */
    public function orderByRaw(string $sql, array $parameters = [], bool $descending = false): self
    {
        $value = new \Light\Entity\Sql\RawSqlValueExpression($sql, $parameters);
        return $descending ? $this->orderByDescending($value) : $this->orderBy($value);
    }
    /** Append a trusted scalar SQL expression to an existing ordering. */
    public function thenByRaw(string $sql, array $parameters = [], bool $descending = false): self
    {
        $value = new \Light\Entity\Sql\RawSqlValueExpression($sql, $parameters);
        return $descending ? $this->thenByDescending($value) : $this->thenBy($value);
    }
    public function thenBy(ValueExpression $keySelector): self
    {
        return $this->with($this->query->thenBy($keySelector));
    }
    public function thenByDescending(ValueExpression $keySelector): self
    {
        return $this->with($this->query->thenByDescending($keySelector));
    }
    /** @return T|null */
    public function firstOrDefault(?ValueExpression $predicate = null, mixed $default = null): mixed
    {
        return $this->entityTerminal('firstOrDefault', $predicate, $default);
    }
    /** @return T */
    public function single(?ValueExpression $predicate = null): object
    {
        return $this->entityTerminal('single', $predicate);
    }
    /** @return T|null */
    public function singleOrDefault(?ValueExpression $predicate = null, mixed $default = null): mixed
    {
        return $this->entityTerminal('singleOrDefault', $predicate, $default);
    }

    /** @return T */
    public function last(?ValueExpression $predicate = null): object
    {
        return $this->entityTerminal('last', $predicate);
    }

    /** @return T|null */
    public function lastOrDefault(?ValueExpression $predicate = null, mixed $default = null): mixed
    {
        return $this->entityTerminal('lastOrDefault', $predicate, $default);
    }

    public function all(ValueExpression $predicate): bool
    {
        return $this->loaded === null ? $this->query->all($this->mapping->convertPredicate($predicate))
            : Queryable::from(new \Light\Linq\InMemoryQueryProvider(['loaded' => $this->loaded]), 'loaded')->all($predicate);
    }
    public function skip(int $count): self { return $this->with($this->query->skip($count)); }
    public function take(int $count): self { return $this->with($this->query->take($count)); }
    public function count(): int { return $this->loaded === null ? $this->query->count() : count($this->loaded); }
    public function any(ValueExpression|\Closure|null $predicate = null): bool
    {
        if ($predicate instanceof \Closure) { return $this->where($predicate)->any(); }
        return $this->loaded !== null && $predicate === null
            ? $this->loaded !== [] : $this->query->any($predicate === null ? null : $this->mapping->convertPredicate($predicate));
    }

    /** @return T */
    public function first(?ValueExpression $predicate = null): object
    {
        $tracking = $this->trackingMode();
        if ($this->loaded !== null && $predicate === null) {
            $entity = $this->loaded[0] ?? throw new \UnderflowException('The query has no elements.');
        } else {
            $this->context->validateIncludes($this->mapping, $this->includes);
            $entity = $this->context->materialize($this->mapping, $this->query->first($predicate === null ? null : $this->mapping->convertPredicate($predicate)), $tracking);
        }
        $this->context->loadIncludes($this->mapping, [$entity], $this->includes, $tracking, QueryFilterRewriter::ignored($this->query->expression()));
        return $entity;
    }

    /**
     * @param int|string|array<string, scalar> $key Scalar for a single key; named components for a composite key.
     * @return T|null
     */
    public function find(int|string|array $key): ?object
    {
        if (!$this->root) {
            throw new \LogicException('find() requires the root DbSet.');
        }
        $key = $this->mapping->normalizeKey($key);
        $tracking = $this->trackingMode();
        $tracked = $tracking === QueryTrackingBehavior::TrackAll && !$this->context->hasQueryFilters($this->mapping) ? $this->context->tracked($this->mapping, $key) : null;
        if ($tracked !== null) {
            $this->context->loadIncludes($this->mapping, [$tracked], $this->includes, $tracking, QueryFilterRewriter::ignored($this->query->expression()));
            return $tracked;
        }
        $query = $this->withTracking($tracking);
        foreach ($key as $name => $value) {
            $query = $query->where(Expr::eq(Expr::field($name), $value));
        }
        $rows = $query->take(1)->toArray();
        return $rows[0] ?? null;
    }

    public function add(object $entity): void { $this->context->add($this->mapping->class, $entity); }

    /**
     * Tracks each entity for insertion when saveChanges() is called.
     *
     * @param iterable<T> $entities
     */
    public function addRange(iterable $entities): void
    {
        foreach ($entities as $entity) {
            $this->add($entity);
        }
    }

    /** Executes a single UPDATE immediately; returns the driver's affected-row count.
     * @param array<string, scalar|null|ValueExpression> $values
     */
    public function executeUpdate(array $values): int
    {
        $this->validateModification();
        return $this->context->executeModification($this->mapping, $this->query, $values);
    }

    /** Executes a single DELETE immediately without loading or detaching entities. */
    public function executeDelete(): int
    {
        $this->validateModification();
        return $this->context->executeModification($this->mapping, $this->query);
    }

    private function validateModification(): void
    {
        if ($this->includes !== [] || $this->loaded !== null) {
            throw new \LogicException('Bulk writes do not support includes or preloaded relationship collections.');
        }
    }

    public function remove(object $entity): void { $this->context->remove($this->mapping->class, $entity); }

    public function getIterator(): \Traversable
    {
        $tracking = $this->trackingMode();
        $this->context->validateIncludes($this->mapping, $this->includes);
        if ($this->loaded !== null) {
            $this->context->loadIncludes($this->mapping, $this->loaded, $this->includes, $tracking, QueryFilterRewriter::ignored($this->query->expression()));
            yield from $this->loaded;
        } elseif ($tracking === QueryTrackingBehavior::NoTracking && $this->includes === []) {
            yield from $this->context->streamNoTrackingEntities($this->mapping, $this->query);
        } elseif ($tracking === QueryTrackingBehavior::NoTracking
            && ($entities = $this->context->fetchNoTrackingEntities($this->mapping, $this->query)) !== null) {
            $this->context->loadIncludes($this->mapping, $entities, $this->includes, $tracking, QueryFilterRewriter::ignored($this->query->expression()));
            yield from $entities;
        } elseif ($this->includes !== []) {
            $entities = [];
            foreach ($this->query as $row) {
                $entities[] = $this->context->materialize($this->mapping, $row, $tracking);
            }
            $this->context->loadIncludes($this->mapping, $entities, $this->includes, $tracking, QueryFilterRewriter::ignored($this->query->expression()));
            yield from $entities;
        } else {
            foreach ($this->query as $row) {
                $entity = $this->context->materialize($this->mapping, $row, $tracking);
                $this->context->loadIncludes($this->mapping, [$entity], [], $tracking, QueryFilterRewriter::ignored($this->query->expression()));
                yield $entity;
            }
        }
    }
    /** @return list<T> */
    public function toArray(): array
    {
        // Materialization can use native bulk hydration; foreach/cursor keep streaming.
        if ($this->loaded === null && $this->includes === [] && $this->trackingMode() === QueryTrackingBehavior::NoTracking) {
            $entities = $this->context->fetchNoTrackingEntities($this->mapping, $this->query);
            if ($entities !== null) { return $entities; }
        }
        return iterator_to_array($this, false);
    }

    private function entityTerminal(string $method, ?ValueExpression $predicate, mixed $default = null): mixed
    {
        $tracking = $this->trackingMode();
        $this->context->validateIncludes($this->mapping, $this->includes);
        $query = $this->loaded === null ? $this->query
            : Queryable::from(new \Light\Linq\InMemoryQueryProvider(['loaded' => $this->loaded]), 'loaded');
        $result = $query->$method($this->loaded === null && $predicate !== null ? $this->mapping->convertPredicate($predicate) : $predicate);
        if ($result === null) return $default;
        $entity = $this->loaded === null ? $this->context->materialize($this->mapping, $result, $tracking) : $result;
        $this->context->loadIncludes($this->mapping, [$entity], $this->includes, $tracking, QueryFilterRewriter::ignored($this->query->expression()));
        return $entity;
    }

    private function setQuery(string $method, IQueryable $second): Queryable
    {
        if ($second instanceof self) {
            $second = $second->query->select(Expr::fields(...$second->mapping->properties));
        }
        return $this->query->select(Expr::fields(...$this->mapping->properties))->$method($second);
    }

    private function aggregateQuery(): Queryable
    {
        return $this->loaded === null ? $this->query
            : Queryable::from(new \Light\Linq\InMemoryQueryProvider(['loaded' => $this->loaded]), 'loaded');
    }

    private function trackingMode(): QueryTrackingBehavior
    {
        return $this->trackingBehavior ?? $this->context->changeTracker->queryTrackingBehavior;
    }

    private function withTracking(QueryTrackingBehavior $tracking): self
    {
        // Cached entities cannot be reused when switching query tracking modes.
        $loaded = $this->trackingMode() === $tracking ? $this->loaded : null;
        return new self($this->context, $this->mapping, $this->query, $this->root, $this->includes, $loaded, $tracking);
    }

    private function with(Queryable $query): self
    {
        return new self($this->context, $this->mapping, $query, false, $this->includes, trackingBehavior: $this->trackingBehavior);
    }
}
