<?php

declare(strict_types=1);

namespace Light\Entity\Migrations;

final readonly class DropTableOperation implements MigrationOperation
{
    public function __construct(public string $table) { SchemaNames::table($table); }
}
