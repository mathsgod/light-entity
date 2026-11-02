<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Linq\Expr;
use Light\Linq\Queryable;
use Light\Linq\Expression\ValueExpression;

/** @template T of object @implements \IteratorAggregate<int, T> */
final readonly class DbSet implements \IteratorAggregate, \Countable
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

    /** Start a composable SQL query on an unfiltered entity set. */
    public function fromSqlRaw(string $sql, array $parameters = []): self
    {
        if (!$this->root || !$this->query->expression() instanceof \Light\Linq\Expression\SourceExpression) {
            throw new \LogicException('fromSqlRaw() requires the root DbSet; apply it before query operators.');
        }
        $query = new Queryable($this->query->provider(), new \Light\Entity\Sql\RawSqlSourceExpression($sql, $parameters));
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

    public function asNoTracking(): self
    {
        return $this->withTracking(QueryTrackingBehavior::NoTracking);
    }

    public function asTracking(): self
    {
        return $this->withTracking(QueryTrackingBehavior::TrackAll);
    }

    public function where(ValueExpression|array $predicate): self
    {
        if (is_array($predicate)) { $predicate = WhereFilter::parse($predicate); }
        return $this->with($this->query->where($predicate));
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
    public function select(ValueExpression $selector): Queryable
    {
        return $this->query->select($selector);
    }

    /** Result fields use outer.* and inner.*; keys are relative to each DbSet. */
    public function join(
        self $inner,
        ValueExpression $outerKey,
        ValueExpression $innerKey,
        ValueExpression $resultSelector,
    ): Queryable
    {
        return $this->query->join($inner->query, $outerKey, $innerKey, $resultSelector);
    }

    public function leftJoin(
        self $inner,
        ValueExpression $outerKey,
        ValueExpression $innerKey,
        ValueExpression $resultSelector,
    ): Queryable
    {
        return $this->query->leftJoin($inner->query, $outerKey, $innerKey, $resultSelector);
    }

    public function orderBy(ValueExpression $selector): self
    {
        return $this->with($this->query->orderBy($selector));
    }

    public function orderByDescending(ValueExpression $selector): self
    {
        return $this->with($this->query->orderByDescending($selector));
    }

    public function thenBy(ValueExpression $selector): self
    {
        return $this->with($this->query->thenBy($selector));
    }
    public function thenByDescending(ValueExpression $selector): self
    {
        return $this->with($this->query->thenByDescending($selector));
    }
    /** @return T|null */
    public function firstOrDefault(?ValueExpression $predicate = null, ?object $default = null): ?object
    {
        return $this->entityTerminal('firstOrDefault', $predicate, $default);
    }
    /** @return T */
    public function single(?ValueExpression $predicate = null): object
    {
        return $this->entityTerminal('single', $predicate);
    }
    public function all(ValueExpression $predicate): bool
    {
        return $this->loaded === null ? $this->query->all($predicate)
            : Queryable::from(new \Light\Linq\InMemoryQueryProvider(['loaded' => $this->loaded]), 'loaded')->all($predicate);
    }
    public function skip(int $count): self { return $this->with($this->query->skip($count)); }
    public function take(int $count): self { return $this->with($this->query->take($count)); }
    public function count(): int { return $this->loaded === null ? $this->query->count() : count($this->loaded); }
    public function any(?ValueExpression $predicate = null): bool
    {
        return $this->loaded !== null && $predicate === null
            ? $this->loaded !== [] : $this->query->any($predicate);
    }

    /** @return T */
    public function first(?ValueExpression $predicate = null): object
    {
        $tracking = $this->trackingMode();
        if ($this->loaded !== null && $predicate === null) {
            $entity = $this->loaded[0] ?? throw new \UnderflowException('The query has no elements.');
        } else {
            $this->context->validateIncludes($this->mapping, $this->includes);
            $entity = $this->context->materialize($this->mapping, $this->query->first($predicate), $tracking);
        }
        $this->context->loadIncludes($this->mapping, [$entity], $this->includes, $tracking);
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
        $tracked = $tracking === QueryTrackingBehavior::TrackAll ? $this->context->tracked($this->mapping, $key) : null;
        if ($tracked !== null) {
            $this->context->loadIncludes($this->mapping, [$tracked], $this->includes, $tracking);
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

    public function remove(object $entity): void { $this->context->remove($this->mapping->class, $entity); }

    public function getIterator(): \Traversable
    {
        $tracking = $this->trackingMode();
        $this->context->validateIncludes($this->mapping, $this->includes);
        if ($this->loaded !== null) {
            $this->context->loadIncludes($this->mapping, $this->loaded, $this->includes, $tracking);
            yield from $this->loaded;
        } elseif ($tracking === QueryTrackingBehavior::NoTracking
            && ($entities = $this->context->fetchNoTrackingEntities($this->mapping, $this->query)) !== null) {
            $this->context->loadIncludes($this->mapping, $entities, $this->includes, $tracking);
            yield from $entities;
        } elseif ($this->includes !== []) {
            $entities = [];
            foreach ($this->query as $row) {
                $entities[] = $this->context->materialize($this->mapping, $row, $tracking);
            }
            $this->context->loadIncludes($this->mapping, $entities, $this->includes, $tracking);
            yield from $entities;
        } else {
            foreach ($this->query as $row) {
                yield $this->context->materialize($this->mapping, $row, $tracking);
            }
        }
    }
    /** @return list<T> */
    public function toArray(): array { return iterator_to_array($this, false); }

    private function entityTerminal(string $method, ?ValueExpression $predicate, ?object $default = null): ?object
    {
        $tracking = $this->trackingMode();
        $this->context->validateIncludes($this->mapping, $this->includes);
        $query = $this->loaded === null ? $this->query
            : Queryable::from(new \Light\Linq\InMemoryQueryProvider(['loaded' => $this->loaded]), 'loaded');
        $result = $query->$method($predicate);
        if ($result === null) return $default;
        $entity = $this->loaded === null ? $this->context->materialize($this->mapping, $result, $tracking) : $result;
        $this->context->loadIncludes($this->mapping, [$entity], $this->includes, $tracking);
        return $entity;
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
