<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Entity\Schema\SchemaReader;

final class Model implements IModel
{
    /** @var array<class-string, EntityMapping> */
    private array $mappings = [];
    private array $hierarchies = [];
    private array $hierarchyColumns = [];
    private array $discriminators = [];

    /** @param iterable<EntityMapping> $mappings */
    public function __construct(private readonly SchemaReader $schema, iterable $mappings = [], private readonly ?\Closure $initialize = null)
    {
        foreach ($mappings as $mapping) {
            if (isset($this->mappings[$mapping->class])) {
                throw new \InvalidArgumentException('Duplicate entity mapping.');
            }
            $this->mappings[$mapping->class] = $mapping;
        }
    }

    public function findEntityType(string $class): ?EntityMapping
    {
        return $this->mappings[$class] ?? null;
    }

    public function getEntityType(string $class, ?string $table = null): EntityMapping
    {
        ($this->initialize)?->__invoke();
        if (($mapping = $this->findEntityType($class)) !== null) {
            if ($table !== null && $mapping->table !== $table) {
                throw new \LogicException('An entity class can map to only one table per context.');
            }
            return $mapping;
        }

        $reflection = new \ReflectionClass($class);
        $root = $reflection;
        if ($reflection->isSubclassOf(Entity::class)) {
            while (($parent = $root->getParentClass()) !== false && $parent->getName() !== Entity::class) $root = $parent;
        }
        $rootClass = $root->getName();
        $knownTypes = array_values(array_filter(get_declared_classes(), static fn ($type) => is_subclass_of($type, $rootClass)));
        $config = $this->discriminators[$rootClass] ?? null;
        $tph = $reflection->isSubclassOf(Entity::class)
            && ($class !== $rootClass || $knownTypes !== [] || $config !== null);
        if (!$tph) {
            $attribute = $reflection->getAttributes(Table::class)[0] ?? null;
            $table ??= $attribute?->newInstance()->name ?? self::tableName($reflection);
            return $this->mappings[$class] = EntityMapping::fromSchema($class, $table, $this->schema->columns($table));
        }
        $attribute = $root->getAttributes(Table::class)[0] ?? null;
        $table ??= $this->hierarchies[$rootClass]->table ?? $attribute?->newInstance()->name ?? self::tableName($root);
        $hierarchy = $this->hierarchies[$rootClass] ?? null;
        if ($hierarchy !== null && $hierarchy->table !== $table) {
            throw new \LogicException('TPH entity types must share the root table.');
        }
        $childTable = $class === $rootClass ? null : ($reflection->getAttributes(Table::class)[0] ?? null)?->newInstance()->name;
        if ($childTable !== null && $childTable !== $table) {
            throw new \LogicException('TPH derived types cannot map to a separate table. Configure the table on the root entity.');
        }
        if (isset($this->mappings[$rootClass]) && $this->mappings[$rootClass]->hierarchy === null) {
            throw new \LogicException('Register derived types before the root mapping is discovered, or configure hasDiscriminator() on the root. Explicit mappings do not infer inheritance.');
        }
        foreach ($this->mappings as $existing) {
            if (is_a($existing->class, $rootClass, true) && $existing->hierarchy === null) {
                throw new \LogicException('Cannot combine explicit independent mappings with an inferred TPH hierarchy. Map the complete hierarchy explicitly or use TPH conventions.');
            }
        }
        $columns = $this->hierarchyColumns[$rootClass] ?? $this->schema->columns($table);
        $hierarchy ??= new EntityHierarchy($rootClass, $table, $config['column'] ?? 'Discriminator');
        $column = array_values(array_filter($columns, fn ($column) => $column->name === $hierarchy->column))[0] ?? null;
        if ($column === null || $column->computed || $column->primary || $column->generated
            || preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint|bool|boolean|real|float|double|decimal|numeric|binary|varbinary|blob|tinyblob|mediumblob|longblob|json|date|datetime|timestamp|time|year|bit)\\b/i', $column->type)) {
            throw new \LogicException('TPH requires a writable string discriminator column: '.$hierarchy->column);
        }
        foreach (array_unique([$rootClass, ...$knownTypes, ...array_keys($config['values'] ?? []), $class]) as $type) {
            $hierarchy->register($type, $config['values'][$type] ?? null);
        }
        $mapping = EntityMapping::fromSchema($class, $table, $columns, hierarchy: $hierarchy);
        $this->hierarchyColumns[$rootClass] = $columns;
        $this->hierarchies[$rootClass] = $hierarchy;
        return $this->mappings[$class] = $mapping;
    }

    private static function tableName(\ReflectionClass $type): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $type->getShortName())).'s';
    }

    /** @internal Called after onModelCreating, before schema discovery. */
    public function configureInheritance(array $discriminators): void
    {
        foreach ($discriminators as $root => $config) {
            $parent = get_parent_class($root);
            if ($parent !== Entity::class) throw new \LogicException('Configure hasDiscriminator() on the hierarchy root.');
        }
        $this->discriminators = $discriminators;
    }

    public function getEntityTypes(): array
    {
        return array_values($this->mappings);
    }
}
