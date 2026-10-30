<?php

declare(strict_types=1);

namespace PhpEntity;

use PhpLinq\Expr;
use PhpLinq\Queryable;
use PhpLinq\Expression\ValueExpression;

/** @template T of object @implements \IteratorAggregate<int, T> */
final readonly class DbSet implements \IteratorAggregate, \Countable
{
    public function __construct(
        private DbContext $context,
        private EntityMapping $mapping,
        private Queryable $query,
        private bool $root = true,
    ) {}

    public function where(ValueExpression $predicate): self
    {
        return $this->with($this->query->where($predicate));
    }

    /** Raw row query for composition; results are not tracked entities. */
    public function asQueryable(): Queryable
    {
        return $this->query;
    }

    /** Returns projected values without tracking them as entities. */
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

    public function skip(int $count): self { return $this->with($this->query->skip($count)); }
    public function take(int $count): self { return $this->with($this->query->take($count)); }
    public function count(): int { return $this->query->count(); }
    public function any(?ValueExpression $predicate = null): bool { return $this->query->any($predicate); }

    /** @return T */
    public function first(?ValueExpression $predicate = null): object
    {
        return $this->context->materialize($this->mapping, $this->query->first($predicate));
    }

    /** @return T|null */
    public function find(int|string $key): ?object
    {
        if (!$this->root) {
            throw new \LogicException('find() requires the root DbSet.');
        }
        $tracked = $this->context->tracked($this->mapping, $key);
        if ($tracked !== null) {
            return $tracked;
        }
        $rows = $this->where(Expr::eq(Expr::field($this->mapping->key), $key))->take(1)->toArray();
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
        foreach ($this->query as $row) {
            yield $this->context->materialize($this->mapping, $row);
        }
    }

    /** @return list<T> */
    public function toArray(): array { return iterator_to_array($this, false); }

    private function with(Queryable $query): self
    {
        return new self($this->context, $this->mapping, $query, false);
    }
}
