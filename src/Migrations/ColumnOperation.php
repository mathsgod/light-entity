<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

final readonly class ColumnOperation implements MigrationOperation
{
    public function __construct(
        public string $action,
        public string $table,
        public string $name,
        public ?ColumnDefinition $definition = null,
        public ?string $newName = null,
    ) {
        SchemaNames::table($table);
        SchemaNames::identifier($name);
        if (!in_array($action, ['add', 'alter', 'rename', 'drop'], true)) {
            throw new \InvalidArgumentException('Unknown column operation.');
        }
        if (in_array($action, ['add', 'alter'], true)) {
            if ($definition === null || $definition->name !== $name || $newName !== null) {
                throw new \InvalidArgumentException('Adding or altering a column requires its complete definition.');
            }
            if ($definition->primaryKey || $definition->autoIncrement) {
                throw new \InvalidArgumentException('Adding or altering primary keys/identity columns is not supported.');
            }
        } elseif ($definition !== null) {
            throw new \InvalidArgumentException('Rename/drop operations do not accept a column definition.');
        }
        if ($action === 'rename') {
            SchemaNames::identifier($newName ?? '');
        } elseif ($newName !== null) {
            throw new \InvalidArgumentException('Only rename accepts a new column name.');
        }
    }
}
