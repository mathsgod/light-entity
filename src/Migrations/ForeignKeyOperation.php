<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

final readonly class ForeignKeyOperation implements MigrationOperation
{
    public function __construct(
        public string $action,
        public string $table,
        public string $name,
        public array $columns = [],
        public ?string $principalTable = null,
        public array $principalColumns = [],
        public string $onDelete = 'NO ACTION',
        public string $onUpdate = 'NO ACTION',
    ) {
        SchemaNames::table($table);
        SchemaNames::identifier($name);
        if ($action === 'add') {
            SchemaNames::columns($columns);
            SchemaNames::table($principalTable ?? '');
            SchemaNames::columns($principalColumns);
            if (count($columns) !== count($principalColumns)) {
                throw new \InvalidArgumentException('Foreign-key column counts must match.');
            }
            foreach ([$onDelete, $onUpdate] as $rule) {
                if (!in_array($rule, ['NO ACTION', 'RESTRICT', 'CASCADE', 'SET NULL'], true)) {
                    throw new \InvalidArgumentException('Unsupported foreign-key action.');
                }
            }
        } elseif ($action !== 'drop' || $columns !== [] || $principalTable !== null || $principalColumns !== []
            || $onDelete !== 'NO ACTION' || $onUpdate !== 'NO ACTION') {
            throw new \InvalidArgumentException('Invalid foreign-key operation.');
        }
    }
}
