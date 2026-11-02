<?php

declare(strict_types=1);

namespace Light\Entity;

/** Entity metadata owned by one context. */
interface IModel
{
    /**
     * Returns cached metadata, or null. Never reads the database schema.
     * @param class-string $class
     */
    public function findEntityType(string $class): ?EntityMapping;

    /**
     * Discovers and caches metadata if necessary.
     * @param class-string $class
     */
    public function getEntityType(string $class, ?string $table = null): EntityMapping;

    /**
     * Returns only metadata already loaded or explicitly registered.
     * @return list<EntityMapping>
     */
    public function getEntityTypes(): array;
}
