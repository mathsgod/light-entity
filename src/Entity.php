<?php

declare(strict_types=1);

namespace PhpEntity;

/** Optional base for schema-driven entities without manually declared properties. */
abstract class Entity implements \JsonSerializable
{
    private array $data = [];
    private ?DbContext $relationContext = null;
    private ?EntityMapping $relationMapping = null;
    private QueryTrackingBehavior $relationTracking = QueryTrackingBehavior::TrackAll;

    /** @internal */
    final public function attachRelations(DbContext $context, EntityMapping $mapping, QueryTrackingBehavior $tracking = QueryTrackingBehavior::TrackAll): void
    {
        $this->relationContext = $context;
        $this->relationMapping = $mapping;
        $this->relationTracking = $tracking;
    }

    /** @internal Bulk initialization for validated, schema-driven native hydration. */
    final public function initializeFromRow(array $values): void
    {
        $this->data = $values;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($arguments !== [] || !preg_match('/^get[A-Z]/', $name)) {
            throw new \BadMethodCallException("Unknown relationship getter: {$name}");
        }
        if ($this->relationContext === null) {
            throw new \LogicException('Relationship getters require an entity attached to a DbContext.');
        }
        return $this->relationContext->related($this, $this->relationMapping, substr($name, 3), $this->relationTracking);
    }


    public function __get(string $name): mixed { return $this->data[$name] ?? null; }
    public function __set(string $name, mixed $value): void { $this->data[$name] = $value; }
    public function __isset(string $name): bool { return isset($this->data[$name]); }
    public function hasValue(string $name): bool { return array_key_exists($name, $this->data); }

    public function jsonSerialize(): array
    {
        /** @var array<class-string, array{properties: list<\ReflectionProperty>, dynamic: bool}> $metadataByClass */
        static $metadataByClass = [];
        $metadata = $metadataByClass[static::class] ??= self::serializationMetadata(static::class);
        if (!$metadata['dynamic'] && $metadata['properties'] === []) {
            return $this->data;
        }
        $values = $this->data;
        if ($metadata['dynamic']) {
            // Custom setters / AllowDynamicProperties can create public properties
            // that differ per instance, so a class-level list is insufficient.
            foreach (get_mangled_object_vars($this) as $name => $value) {
                if (!str_contains((string)$name, "\0")) {
                    $values[$name] = $value;
                }
            }
            return $values;
        }
        foreach ($metadata['properties'] as $property) {
            if ($property->isInitialized($this)) {
                // Preserve raw backing values, without invoking PHP 8.4 get hooks.
                $values[$property->getName()] = PHP_VERSION_ID >= 80400
                    ? $property->getRawValue($this) : $property->getValue($this);
            }
        }
        return $values;
    }

    /** @param class-string $class @return array{properties: list<\ReflectionProperty>, dynamic: bool} */
    private static function serializationMetadata(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $properties = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic() && !(PHP_VERSION_ID >= 80400 && $property->isVirtual())) {
                $properties[] = $property;
            }
        }
        $dynamic = $reflection->getMethod('__set')->getDeclaringClass()->getName() !== self::class;
        for ($type = $reflection; $type !== false; $type = $type->getParentClass()) {
            if ($type->getAttributes(\AllowDynamicProperties::class) !== []) {
                $dynamic = true;
                break;
            }
        }
        return ['properties' => $properties, 'dynamic' => $dynamic];
    }
}
