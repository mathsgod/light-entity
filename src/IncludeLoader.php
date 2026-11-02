<?php
declare(strict_types=1);

namespace Light\Entity;

use PDO;
use Light\Entity\Sql\SqlDialect;

/** Batches relationship queries and keeps loaded results separate from unknown results. */
final class IncludeLoader
{
    /** @var \WeakMap<object, array<string, array>> */
    private \WeakMap $loaded;
    private array $relations = [];

    public function __construct(
        private DbContext $context,
        private PDO $pdo,
        private SqlDialect $dialect,
        private RelationshipResolver $resolver,
    ) {
        $this->clear();
    }

    public function clear(): void
    {
        $this->loaded = new \WeakMap();
        $this->relations = [];
    }

    public function validate(EntityMapping $mapping, array $paths): void
    {
        foreach ($this->tree($paths) as $name => $children) {
            if (!is_a($mapping->class, Entity::class, true)) {
                throw new \LogicException('Include sources must extend Entity to expose relationship getters.');
            }
            $relation = $this->relation($mapping, $name);
            $this->validate($relation['mapping'], $children);
        }
    }

    /** null means not loaded; [] means loaded with no matching rows. */
    public function cached(object $entity, string $name, array $relation, QueryTrackingBehavior $tracking = QueryTrackingBehavior::TrackAll): ?array
    {
        $entry = ($this->loaded[$entity] ?? [])[$this->normalize($name)] ?? null;
        if ($entry === null || $entry['tracking'] !== $tracking || $entry['relation'] !== $relation
            || $entry['key'] !== $this->key($entity, $relation['local'])) {
            return null;
        }
        // A related entity's FK/key may have been changed in memory as well.
        foreach ($entry['entities'] as $i => $target) {
            if ($this->key($target, $relation['remote']) !== $entry['targetKeys'][$i]) { return null; }
        }
        return $entry['entities'];
    }

    public function load(EntityMapping $mapping, array $entities, array $paths, QueryTrackingBehavior $tracking = QueryTrackingBehavior::TrackAll): void
    {
        $this->validate($mapping, $paths);
        foreach ($this->tree($paths) as $name => $children) {
            $relation = $this->relation($mapping, $name);
            $pending = [];
            $keys = [];
            foreach ($entities as $entity) {
                if ($this->cached($entity, $name, $relation, $tracking) !== null) { continue; }
                $key = $this->key($entity, $relation['local']);
                $pending[] = [$entity, $key];
                if ($key !== null) { $keys[$key] = $this->values($entity, $relation['local']); }
            }
            $groups = [];
            // Keep both parameter counts and composite OR expression depth bounded.
            $batchSize = max(1, intdiv(200, count($relation['remote'])));
            foreach (array_chunk(array_values($keys), $batchSize) as $batch) {
                foreach ($this->fetch($relation, $batch) as [$row, $match]) {
                    $target = $this->context->materialize($relation['mapping'], $row, $tracking);
                    $key = $match ?? $this->key($target, $relation['remote']);
                    if ($key !== null) { $groups[$key][] = $target; }
                }
            }
            foreach ($pending as [$entity, $key]) {
                $targets = $key === null ? [] : ($groups[$key] ?? []);
                $entries = $this->loaded[$entity] ?? [];
                $entries[$this->normalize($name)] = [
                    'relation' => $relation, 'key' => $key, 'tracking' => $tracking,
                    'entities' => $targets,
                    'targetKeys' => array_map(fn ($target) => $this->key($target, $relation['remote']), $targets),
                ];
                $this->loaded[$entity] = $entries;
            }
            if ($children !== []) {
                $targets = new \SplObjectStorage();
                foreach ($entities as $entity) {
                    foreach ($this->cached($entity, $name, $relation, $tracking) ?? [] as $target) {
                        $targets->offsetSet($target);
                    }
                }
                $this->load($relation['mapping'], iterator_to_array($targets, false), $children, $tracking);
            }
        }
    }

    private function fetch(array $relation, array $keys): array
    {
        $quote = $this->dialect->quoteIdentifier(...);
        $columns = $relation['remote'];
        $parameters = [];
        // Let the database match string keys: PHP equality cannot reproduce SQL
        // collations (case folding, trailing spaces, etc.). Tag each key's rows
        // within one batch query so equivalent spellings reach every parent.
        if (array_filter($keys, fn ($key) => array_filter($key, is_string(...)) !== []) !== []) {
            $alias = '__phpentity_include_match';
            while (in_array(strtolower($alias), array_map(strtolower(...), $relation['mapping']->properties), true)) { $alias .= '_'; }
            $where = implode(' AND ', array_map(fn ($column) => $quote($column).' = ?', $columns));
            $selects = [];
            foreach ($keys as $i => $key) {
                $selects[] = 'SELECT *, '.$i.' AS '.$quote($alias).' FROM '.$quote($relation['mapping']->table).' WHERE '.$where;
                array_push($parameters, ...$key);
            }
            $statement = $this->pdo->prepare(implode(' UNION ALL ', $selects));
            $statement->execute($parameters);
            $results = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $match = serialize(array_map(strval(...), $keys[(int) $row[$alias]]));
                unset($row[$alias]);
                $results[] = [$row, $match];
            }
            return $results;
        }
        if (count($columns) === 1) {
            $where = $quote($columns[0]).' IN ('.implode(', ', array_fill(0, count($keys), '?')).')';
            $parameters = array_column($keys, 0);
        } else {
            $tuple = '('.implode(' AND ', array_map(fn ($column) => $quote($column).' = ?', $columns)).')';
            $where = implode(' OR ', array_fill(0, count($keys), $tuple));
            foreach ($keys as $key) { array_push($parameters, ...$key); }
        }
        $statement = $this->pdo->prepare('SELECT * FROM '.$quote($relation['mapping']->table).' WHERE '.$where);
        $statement->execute($parameters);
        return array_map(fn ($row) => [$row, null], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function values(object $entity, array $columns): array
    {
        return array_map(fn ($column) => $entity->$column ?? null, $columns);
    }

    private function key(object $entity, array $columns): ?string
    {
        $values = $this->values($entity, $columns);
        if (in_array(null, $values, true)) { return null; }
        // Match scalar keys regardless of PDO's integer/string representation.
        return serialize(array_map(strval(...), $values));
    }

    private function relation(EntityMapping $mapping, string $name): array
    {
        $key = spl_object_id($mapping).':'.$this->normalize($name);
        return $this->relations[$key] ??= $this->resolver->resolve($mapping, $name);
    }

    /** Merge duplicate and overlapping paths into one branch per relationship. */
    private function tree(array $paths): array
    {
        $tree = [];
        foreach ($paths as $path) {
            [$head, $tail] = array_pad(explode('.', $path, 2), 2, null);
            $name = $this->normalize($head);
            $tree[$name] ??= [];
            if ($tail !== null) { $tree[$name][] = $tail; }
        }
        return $tree;
    }

    private function normalize(string $name): string { return strtolower(str_replace('_', '', $name)); }
}
