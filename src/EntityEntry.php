<?php

declare(strict_types=1);

namespace Light\Entity;

/**
 * A live view of an entity's tracking record. Original values are independent snapshots.
 *
 * @property EntityState $state
 * @property-read array<string, mixed> $originalValues
 * @property-read array<string, mixed> $currentValues
 */
final class EntityEntry
{
    /** @internal Obtain entries through DbContext::entry() or ChangeTracker::entries(). */
    public function __construct(
        public readonly object $entity,
        public readonly DbContext $context,
        public readonly EntityMapping $metadata,
        private readonly ChangeTracker $tracker,
    ) {}

    public function __get(string $name): mixed
    {
        return match ($name) {
            'state' => $this->tracker->state($this->entity),
            'originalValues' => $this->tracker->originalValues($this->entity, $this->metadata),
            'currentValues' => $this->metadata->values($this->entity),
            default => throw new \OutOfBoundsException("Unknown entry property: {$name}"),
        };
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name !== 'state') {
            throw new \LogicException("Entry property {$name} is read-only or unknown; change the entity's fields instead.");
        }
        if (!$value instanceof EntityState) {
            throw new \InvalidArgumentException('Entry state must be an EntityState.');
        }
        $this->tracker->setState($this->metadata, $this->entity, $value);
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['state', 'originalValues', 'currentValues'], true);
    }
}
