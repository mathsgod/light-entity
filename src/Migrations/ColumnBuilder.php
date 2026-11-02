<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

final class ColumnBuilder
{
    private bool $isNullable = false;
    private bool $isAutoIncrement = false;
    private bool $isPrimaryKey = false;

    public function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly ?int $length = null,
    ) {
        if ($name === '' || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Column names must be non-empty and contain no NUL bytes.');
        }
        if (!in_array($type, ['integer', 'string'], true)
            || ($type === 'string' && ($length === null || $length < 1 || $length > 65535))) {
            throw new \InvalidArgumentException('Use integer or string with a length between 1 and 65535.');
        }
    }

    public function nullable(bool $nullable = true): self
    {
        $this->isNullable = $nullable;
        return $this;
    }

    public function autoIncrement(bool $autoIncrement = true): self
    {
        $this->isAutoIncrement = $autoIncrement;
        return $this;
    }

    public function primaryKey(bool $primaryKey = true): self
    {
        $this->isPrimaryKey = $primaryKey;
        return $this;
    }

    public function build(): ColumnDefinition
    {
        return new ColumnDefinition($this->name, $this->type, $this->length,
            $this->isNullable, $this->isAutoIncrement, $this->isPrimaryKey);
    }
}
