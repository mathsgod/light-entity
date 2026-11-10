<?php

declare(strict_types=1);

namespace Light\Entity;

/** Context-owned TPH type registry shared by all mappings in one hierarchy. @internal */
final class EntityHierarchy
{
    /** @var array<class-string, string> */
    private array $types = [];

    public function __construct(
        public readonly string $root,
        public readonly string $table,
        public readonly string $column = 'Discriminator',
    ) {}

    public function register(string $class, ?string $value = null): void
    {
        if (!is_a($class, $this->root, true)) {
            throw new \InvalidArgumentException('Discriminator type must belong to the entity hierarchy.');
        }
        if ((new \ReflectionClass($class))->isAbstract()) return;
        $value ??= (new \ReflectionClass($class))->getShortName();
        if ($value === '' || (in_array($value, $this->types, true) && ($this->types[$class] ?? null) !== $value)) {
            throw new \InvalidArgumentException('Discriminator values must be non-empty and unique within the hierarchy.');
        }
        $this->types[$class] = $value;
    }

    public function value(string $class): string
    {
        return $this->types[$class] ?? throw new \LogicException('Unregistered TPH entity type: '.$class);
    }

    /** @return list<string> */
    public function valuesFor(string $class): array
    {
        return array_values(array_filter($this->types, static fn ($type) => is_a($type, $class, true), ARRAY_FILTER_USE_KEY));
    }

    public function classFor(array $row, string $requested): string
    {
        $value = $row[$this->column] ?? null;
        $class = is_string($value) ? array_search($value, $this->types, true) : false;
        if ($class === false || !is_a($class, $requested, true)) {
            throw new \LogicException('Unknown or incompatible discriminator value for '.$requested.': '.var_export($value, true));
        }
        return $class;
    }
}
