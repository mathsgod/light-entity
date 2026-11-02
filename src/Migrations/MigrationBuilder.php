<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

/** Collects schema operations without connecting to or modifying a database. */
final class MigrationBuilder
{
    /** @var list<CreateTableOperation> */
    private array $operations = [];

    /** @param callable(TableBuilder): void $configure */
    public function createTable(string $name, callable $configure): self
    {
        $table = new TableBuilder();
        $configure($table);
        $this->operations[] = $table->build($name);
        return $this;
    }

    /** @return list<CreateTableOperation> */
    public function getOperations(): array
    {
        return $this->operations;
    }
}
