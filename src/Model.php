<?php

declare(strict_types=1);

namespace Light\Entity;

use Light\Entity\Schema\SchemaReader;

final class Model implements IModel
{
    /** @var array<class-string, EntityMapping> */
    private array $mappings = [];

    /** @param iterable<EntityMapping> $mappings */
    public function __construct(private readonly SchemaReader $schema, iterable $mappings = [])
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
        if (($mapping = $this->findEntityType($class)) !== null) {
            if ($table !== null && $mapping->table !== $table) {
                throw new \LogicException('An entity class can map to only one table per context.');
            }
            return $mapping;
        }

        $reflection = new \ReflectionClass($class);
        $attribute = $reflection->getAttributes(Table::class)[0] ?? null;
        $table ??= $attribute?->newInstance()->name
            ?? strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $reflection->getShortName())).'s';

        // Failed discovery is not cached, so it can be retried after fixing the schema.
        return $this->mappings[$class] = EntityMapping::fromSchema($class, $table, $this->schema->columns($table));
    }

    public function getEntityTypes(): array
    {
        return array_values($this->mappings);
    }
}
