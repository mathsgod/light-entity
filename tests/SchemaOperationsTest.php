<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\Migrations\{MigrationBuilder, MigrationSqlGenerator, TableBuilder};
use Light\Entity\Sql\{MySqlDialect, SqliteDialect};
use PHPUnit\Framework\TestCase;

final class SchemaOperationsTest extends TestCase
{
    public function testMySqlCompilesAllOperationsInOrder(): void
    {
        $m = new MigrationBuilder();
        $m->addColumn('users', fn (TableBuilder $t) => $t->string('nickname', 60)->nullable()->defaultValue(null))
            ->alterColumn('users', fn (TableBuilder $t) => $t->string('nickname', 120)->defaultValue('guest'))
            ->renameColumn('users', 'nickname', 'display_name')
            ->createIndex('users', 'ux_users_name', ['display_name'], unique: true)
            ->dropIndex('users', 'ux_users_name')
            ->addForeignKey('users', 'fk_users_team', ['team_id'], 'teams', ['id'], onDelete: 'cascade')
            ->dropForeignKey('users', 'fk_users_team')
            ->dropColumn('users', 'display_name')
            ->dropTable('users');
        self::assertSame([
            'ALTER TABLE `users` ADD COLUMN `nickname` VARCHAR(60) NULL DEFAULT NULL',
            "ALTER TABLE `users` MODIFY COLUMN `nickname` VARCHAR(120) NOT NULL DEFAULT X'6775657374'",
            'ALTER TABLE `users` RENAME COLUMN `nickname` TO `display_name`',
            'CREATE UNIQUE INDEX `ux_users_name` ON `users` (`display_name`)',
            'DROP INDEX `ux_users_name` ON `users`',
            'ALTER TABLE `users` ADD CONSTRAINT `fk_users_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION',
            'ALTER TABLE `users` DROP FOREIGN KEY `fk_users_team`',
            'ALTER TABLE `users` DROP COLUMN `display_name`',
            'DROP TABLE `users`',
        ], (new MigrationSqlGenerator(new MySqlDialect()))->generate($m));
    }

    public function testSqliteNativeOperationsAndQualifiedIndexes(): void
    {
        $m = new MigrationBuilder();
        $m->addColumn('main.users', fn (TableBuilder $t) => $t->integer('age')->defaultValue(0))
            ->renameColumn('main.users', 'age', 'years')
            ->createIndex('main.users', 'ix.years', ['years'])
            ->dropIndex('main.users', 'ix.years')
            ->dropColumn('main.users', 'years')
            ->dropTable('main.users');
        self::assertSame([
            'ALTER TABLE "main"."users" ADD COLUMN "age" INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE "main"."users" RENAME COLUMN "age" TO "years"',
            'CREATE INDEX "main"."ix.years" ON "users" ("years")',
            'DROP INDEX "main"."ix.years"',
            'ALTER TABLE "main"."users" DROP COLUMN "years"',
            'DROP TABLE "main"."users"',
        ], (new MigrationSqlGenerator(new SqliteDialect()))->generate($m));
    }

    public function testInvalidOperationsAreNotQueuedAndDefinitionsAreSnapshots(): void
    {
        $m = new MigrationBuilder();
        $bad = [
            fn () => $m->addColumn('users', fn ($t) => null),
            fn () => $m->alterColumn('users', function ($t) { $t->integer('a'); $t->integer('b'); }),
            fn () => $m->addColumn('users', fn ($t) => $t->integer('id')->primaryKey()),
            fn () => $m->addColumn('users', fn ($t) => $t->string('x')->defaultValue([])),
            fn () => $m->addColumn('users', fn ($t) => $t->integer('x')->defaultValue(INF)),
            fn () => $m->addColumn('users', fn ($t) => $t->integer('x')->defaultValue(null)),
            fn () => $m->dropTable('a.b.c'),
            fn () => $m->dropColumn('users', ''),
            fn () => $m->renameColumn('users', 'name', "bad\0name"),
            fn () => $m->createIndex('users', 'idx', []),
            fn () => $m->createIndex('users', 'idx', ['a','A']),
            fn () => $m->dropIndex('users', ''),
            fn () => $m->addForeignKey('users', 'fk', ['a','b'], 'teams', ['id']),
            fn () => $m->addForeignKey('users', 'fk', ['a'], 'teams', ['id'], onDelete: 'CASCADE; DROP TABLE users'),
            fn () => $m->dropForeignKey('', 'fk'),
        ];
        foreach ($bad as $configure) {
            try { $configure(); self::fail('Expected invalid operation.'); }
            catch (\InvalidArgumentException) { self::assertSame([], $m->getOperations()); }
        }
        $column = null;
        $m->addColumn('users', function ($t) use (&$column) { $column = $t->string('name')->nullable()->defaultValue('before'); });
        $column->nullable(false)->defaultValue('after');
        self::assertTrue($m->getOperations()[0]->definition->nullable);
        self::assertSame('before', $m->getOperations()[0]->definition->defaultValue);
        try { $m->addColumn('users', function ($t) { $t->integer('age'); throw new \RuntimeException(); }); }
        catch (\RuntimeException) { self::assertCount(1, $m->getOperations()); }
    }

    public function testSqliteRebuildOperationsAreExplicitlyRejected(): void
    {
        foreach (['alter', 'addFk', 'dropFk'] as $action) {
            $m = new MigrationBuilder();
            match ($action) {
                'alter' => $m->alterColumn('users', fn ($t) => $t->integer('age')),
                'addFk' => $m->addForeignKey('users','fk',['team_id'],'teams',['id']),
                'dropFk' => $m->dropForeignKey('users','fk'),
            };
            try { (new MigrationSqlGenerator(new SqliteDialect()))->generate($m); self::fail('Expected table-rebuild rejection.'); }
            catch (\LogicException $error) { self::assertStringContainsString('rebuild', $error->getMessage()); }
        }
    }

    public function testIdentifiersAreQuotedAndCompositeForeignKeysKeepOrder(): void
    {
        $m = new MigrationBuilder();
        $m->addForeignKey('app.child', 'fk`"', ['a.b','c`'], 'other.parent', ['x','y'], onUpdate:'cascade');
        self::assertSame(['ALTER TABLE `app`.`child` ADD CONSTRAINT `fk``"` FOREIGN KEY (`a.b`, `c```) REFERENCES `other`.`parent` (`x`, `y`) ON DELETE NO ACTION ON UPDATE CASCADE'], (new MigrationSqlGenerator(new MySqlDialect()))->generate($m));
    }
}
