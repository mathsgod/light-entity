<?php

declare(strict_types=1);

namespace Light\Entity;

/** Configures string discriminator values for a table-per-hierarchy mapping. */
final readonly class DiscriminatorBuilder
{
    public function __construct(private ModelBuilder $model, private string $root) {}

    public function hasValue(string $class, string $value): self
    {
        $this->model->setDiscriminatorValue($this->root, $class, $value);
        return $this;
    }
}
