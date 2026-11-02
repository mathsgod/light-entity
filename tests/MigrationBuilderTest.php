<?php

declare(strict_types=1);

namespace PhpEntity\Tests;

use PhpEntity\Migrations\MigrationBuilder;
use PhpEntity\Migrations\MigrationSqlGenerator;
use PhpEntity\Migrations\TableBuilder;
use PhpEntity\Sql\MySqlDialect;
use PhpEntity\Sql\PostgreSqlDialect;
use PhpEntity\Sql\SqliteDialect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationBuilderTest extends TestCase
{
    public function testGeneratesNativeDdlFromTheRequestedCallbackSyntax(): void
    {
        $migration = new MigrationBuilder();
        $migration->createTable('users', function (TableBuilder $table) {
            $table->integer('id')->autoIncrement()->primaryKey();
            $table->string('name', 100);
            $table->string('email', 255)->nullable();
        });
        self::assertSame([
            "CREATE TABLE `users` (\n    `id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT,\n    `name` VARCHAR(100) NOT NULL,\n    `email` VARCHAR(255) NULL\n)",
        ], (new MigrationSqlGenerator(new MySqlDialect()))->generate($migration));
        self::assertSame([
            "CREATE TABLE \"users\" (\n    \"id\" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,\n    \"name\" VARCHAR(100) NOT NULL,\n    \"email\" VARCHAR(255) NULL\n)",
        ], (new MigrationSqlGenerator(new SqliteDialect()))->generate($migration));
    }

    public function testOperationsAreSnapshotsAndFailedCallbacksAreNotQueued(): void
    {
        $migration = new MigrationBuilder();
        $column = null;
        $migration->createTable('users', function (TableBuilder $table) use (&$column) {
            $column = $table->string('name');
        });
        $column->nullable();
        self::assertFalse($migration->getOperations()[0]->columns[0]->nullable);
        try {
            $migration->createTable('failed', function (TableBuilder $table) {
                $table->integer('id');
                throw new \RuntimeException('configuration failed');
            });
            self::fail('Expected callback exception.');
        } catch (\RuntimeException $error) {
            self::assertSame('configuration failed', $error->getMessage());
        }
        self::assertCount(1, $migration->getOperations());
    }

    public static function invalidDefinitions(): iterable
    {
        yield 'empty table' => ['', fn (TableBuilder $t) => $t->integer('id')];
        yield 'empty columns' => ['users', fn (TableBuilder $t) => null];
        yield 'empty column' => ['users', fn (TableBuilder $t) => $t->integer('')];
        yield 'duplicate names' => ['users', function (TableBuilder $t) { $t->integer('id'); $t->integer('ID'); }];
        yield 'zero length' => ['users', fn (TableBuilder $t) => $t->string('name', 0)];
        yield 'negative length' => ['users', fn (TableBuilder $t) => $t->string('name', -1)];
        yield 'excessive length' => ['users', fn (TableBuilder $t) => $t->string('name', 65536)];
        yield 'nullable key' => ['users', fn (TableBuilder $t) => $t->integer('id')->primaryKey()->nullable()];
        yield 'string identity' => ['users', fn (TableBuilder $t) => $t->string('id')->autoIncrement()->primaryKey()];
        yield 'identity without key' => ['users', fn (TableBuilder $t) => $t->integer('id')->autoIncrement()];
        yield 'multiple identities' => ['users', function (TableBuilder $t) { $t->integer('id')->primaryKey()->autoIncrement(); $t->integer('other')->primaryKey()->autoIncrement(); }];
        yield 'empty schema' => ['.users', fn (TableBuilder $t) => $t->integer('id')];
        yield 'nul' => ["users\0", fn (TableBuilder $t) => $t->integer('id')];
    }

    #[DataProvider('invalidDefinitions')]
    public function testInvalidSchemasFailBeforeTheyAreQueued(string $name, callable $configure): void
    {
        $migration = new MigrationBuilder();
        try {
            $migration->createTable($name, $configure);
            self::fail('Expected invalid schema rejection.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $migration->getOperations());
        }
    }

    public function testQualifiedTableAndLiteralColumnNamesAreQuoted(): void
    {
        $migration = new MigrationBuilder();
        $migration->createTable('app.we`ird', fn (TableBuilder $t) => $t->string('a.b`"; DROP TABLE users; --'));
        $sql = (new MigrationSqlGenerator(new MySqlDialect()))->generate($migration)[0];
        self::assertStringContainsString('`app`.`we``ird`', $sql);
        self::assertStringContainsString('`a.b``"; DROP TABLE users; --`', $sql);
    }

    public function testUnsupportedDdlProviderIsExplicitlyRejected(): void
    {
        $this->expectException(\LogicException::class);
        new MigrationSqlGenerator(new PostgreSqlDialect());
    }
}
