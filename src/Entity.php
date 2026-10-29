<?php

declare(strict_types=1);

namespace PhpEntity;

/** Optional base for schema-driven entities without manually declared properties. */
abstract class Entity
{
    private array $data = [];

    public function __get(string $name): mixed { return $this->data[$name] ?? null; }
    public function __set(string $name, mixed $value): void { $this->data[$name] = $value; }
    public function __isset(string $name): bool { return isset($this->data[$name]); }
    public function hasValue(string $name): bool { return array_key_exists($name, $this->data); }
}
