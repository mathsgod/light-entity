<?php

declare(strict_types=1);

namespace PhpEntity;

/** Optional base for schema-driven entities without manually declared properties. */
abstract class Entity
{
    private array $data = [];
    private ?DbContext $relationContext = null;
    private ?EntityMapping $relationMapping = null;

    /** @internal */
    final public function attachRelations(DbContext $context, EntityMapping $mapping): void
    {
        $this->relationContext = $context;
        $this->relationMapping = $mapping;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($arguments !== [] || !preg_match('/^get[A-Z]/', $name)) {
            throw new \BadMethodCallException("Unknown relationship getter: {$name}");
        }
        if ($this->relationContext === null) {
            throw new \LogicException('Relationship getters require an entity attached to a DbContext.');
        }
        return $this->relationContext->related($this, $this->relationMapping, substr($name, 3));
    }


    public function __get(string $name): mixed { return $this->data[$name] ?? null; }
    public function __set(string $name, mixed $value): void { $this->data[$name] = $value; }
    public function __isset(string $name): bool { return isset($this->data[$name]); }
    public function hasValue(string $name): bool { return array_key_exists($name, $this->data); }
}
