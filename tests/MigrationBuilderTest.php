<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\Migrations\MigrationBuilder;
use Light\Entity\Migrations\MigrationSqlGenerator;
use Light\Entity\Migrations\TableBuilder;
use Light\Entity\Sql\MySqlDialect;
use Light\Entity\Sql\PostgreSqlDialect;
use Light\Entity\Sql\SqliteDialect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationBuilderTest extends TestCase
{
    public function testSqliteRejectsMySqlMemberColumns(): void
    {
        $migration = new MigrationBuilder();
        $migration->addColumn('invoices', fn (TableBuilder $table) => $table->set('flags', ['urgent']));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SET columns are only supported by the MySQL migration dialect.');
        (new MigrationSqlGenerator(new SqliteDialect()))->generate($migration);
    }

    public function testExplicitColumnTypesApplyToAllColumnOperations(): void
    {
        $migration = new MigrationBuilder();
        $migration->createTable('invoices', fn (TableBuilder $table) =>
            $table->text('metadata')->columnType('JSON')->nullable());
        $migration->addColumn('invoices', fn (TableBuilder $table) =>
            $table->string('receipt')->columnType('VARBINARY(255)'));
        $migration->alterColumn('invoices', fn (TableBuilder $table) =>
            $table->integer('total')->columnType('DECIMAL(12, 2)'));

        self::assertSame([
            "CREATE TABLE `invoices` (\n    `metadata` JSON NULL\n)",
            'ALTER TABLE `invoices` ADD COLUMN `receipt` VARBINARY(255) NOT NULL',
            'ALTER TABLE `invoices` MODIFY COLUMN `total` DECIMAL(12, 2) NOT NULL',
        ], (new MigrationSqlGenerator(new MySqlDialect()))->generate($migration));
    }

    public function testAdditionalColumnTypesGenerateSqlForBothDialects(): void
    {
        $migration = new MigrationBuilder();
        $migration->createTable('invoices', function (TableBuilder $table): void {
            $table->boolean('paid')->defaultValue(false);
            $table->text('notes')->nullable();
            $table->decimal('amount', precision: 12, scale: 2);
            $table->date('issued_on');
            $table->dateTime('paid_at')->nullable();
        });
        $migration->addColumn('invoices', fn (TableBuilder $table) =>
            $table->binary('receipt')->defaultValue("\x00\xff"));

        foreach ([new MySqlDialect(), new SqliteDialect()] as $dialect) {
            [$create, $add] = (new MigrationSqlGenerator($dialect))->generate($migration);
            $quote = $dialect instanceof MySqlDialect ? '`' : '"';
            self::assertSame("CREATE TABLE {$quote}invoices{$quote} (\n"
                ."    {$quote}paid{$quote} BOOLEAN NOT NULL DEFAULT 0,\n"
                ."    {$quote}notes{$quote} TEXT NULL,\n"
                ."    {$quote}amount{$quote} DECIMAL(12,2) NOT NULL,\n"
                ."    {$quote}issued_on{$quote} DATE NOT NULL,\n"
                ."    {$quote}paid_at{$quote} DATETIME NULL\n)", $create);
            $default = $dialect instanceof MySqlDialect ? "(X'00ff')" : "X'00ff'";
            self::assertSame("ALTER TABLE {$quote}invoices{$quote} ADD COLUMN "
                ."{$quote}receipt{$quote} BLOB NOT NULL DEFAULT {$default}", $add);
        }

        $pdo = new \PDO('sqlite::memory:');
        foreach ((new MigrationSqlGenerator(new SqliteDialect()))->generate($migration) as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("INSERT INTO invoices (amount, issued_on) VALUES (12.34, '2026-11-27')");
        self::assertSame('00FF', $pdo->query('SELECT hex(receipt) FROM invoices')->fetchColumn());
    }

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
        yield 'empty enum' => ['users', fn (TableBuilder $t) => $t->enum('status', [])];
        yield 'duplicate enum members' => ['users', fn (TableBuilder $t) => $t->enum('status', ['draft', 'draft'])];
        yield 'undeclared enum default' => ['users', fn (TableBuilder $t) => $t->enum('status', ['draft'])->defaultValue('paid')];
        yield 'comma in set member' => ['users', fn (TableBuilder $t) => $t->set('flags', ['urgent,reviewed'])];
        yield 'backslash in set member' => ['users', fn (TableBuilder $t) => $t->set('flags', ['urgent\\reviewed'])];
        yield 'type with column constraint' => ['users', fn (TableBuilder $t) => $t->text('metadata')->columnType('JSON NOT NULL')];
        yield 'type with extra column' => ['users', fn (TableBuilder $t) => $t->text('metadata')->columnType('JSON, extra INT')];
        yield 'explicit identity type' => ['users', fn (TableBuilder $t) => $t->integer('id')->columnType('BIGINT')->primaryKey()->autoIncrement()];
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
