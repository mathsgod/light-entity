<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

final readonly class IndexOperation implements MigrationOperation
{
    public function __construct(
        public string $action,
        public string $table,
        public string $name,
        public array $columns = [],
        public bool $unique = false,
    ) {
        SchemaNames::table($table);
        SchemaNames::identifier($name);
        if ($action === 'create') {
            SchemaNames::columns($columns);
        } elseif ($action !== 'drop' || $columns !== [] || $unique) {
            throw new \InvalidArgumentException('Invalid index operation.');
        }
    }
}
