<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use PDO;
use Light\Entity\DbContext;
use Light\Entity\Entity;
use Light\Entity\Migrations\MigrationBuilder;
use Light\Entity\Migrations\TableBuilder;
use PHPUnit\Framework\TestCase;

class MigrationExecutionTest extends TestCase
{
    protected function connection(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        return new PDO('sqlite::memory:');
    }

    public function testPreviewThenExecuteAndPersistWithSchemaDiscoveredEntities(): void
    {
        $pdo = $this->connection();
        $db = new DbContext($pdo);
        $migration = new MigrationBuilder();
        $migration->createTable('users', function (TableBuilder $table) {
            $table->integer('id')->autoIncrement()->primaryKey();
            $table->string('name', 100);
            $table->string('email', 255)->nullable();
        });
        self::assertStringContainsString('CREATE TABLE', $db->database->generateMigrationScript($migration));
        try {
            $pdo->query('SELECT * FROM users');
            self::fail('Preview must not create the table.');
        } catch (\PDOException) {
            self::assertTrue(true);
        }
        $db->database->executeMigration($migration);
        $users = $db->set(MigrationUser::class, 'users');
        $mapping = $db->model->getEntityType(MigrationUser::class);
        self::assertTrue($mapping->generatedKey);
        self::assertTrue($mapping->columns['email']->nullable);
        self::assertFalse($mapping->columns['name']->nullable);
        $user = new MigrationUser();
        $user->name = 'Ada';
        $users->add($user);
        self::assertSame(1, $db->saveChanges());
        self::assertSame(1, $user->id);
        self::assertNull($user->email);
        self::assertSame('Ada', (new DbContext($pdo))->set(MigrationUser::class, 'users')->find(1)->name);
    }

    public function testManualIntegerKeyIsNotDiscoveredAsGenerated(): void
    {
        $db = new DbContext($this->connection());
        $migration = new MigrationBuilder();
        $migration->createTable('users', function (TableBuilder $table) {
            $table->integer('id')->primaryKey();
            $table->string('name');
        });
        $db->database->executeMigration($migration);
        self::assertFalse($db->model->getEntityType(MigrationUser::class, 'users')->generatedKey);
        $user = new MigrationUser();
        $user->id = 42;
        $user->name = 'Manual';
        $db->set(MigrationUser::class)->add($user);
        self::assertSame(1, $db->saveChanges());
        self::assertSame(42, $user->id);
    }

    public function testDdlEscapesIdentifiersAndPreservesLiteralDottedColumnName(): void
    {
        $db = new DbContext($this->connection());
        $migration = new MigrationBuilder();
        $migration->createTable('we`"ird', fn (TableBuilder $table) => $table->string('a.b`"; DROP TABLE users; --')->nullable());
        $db->database->executeMigration($migration);
        $pdo = $db->database->getDbConnection();
        $quote = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`we``"ird`' : '"we`""ird"';
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM '.$quote)->fetchColumn());
    }

    public function testRequiredAndPrimaryKeyConstraintsAreEnforcedByTheDatabase(): void
    {
        $db = new DbContext($this->connection());
        $migration = new MigrationBuilder();
        $migration->createTable('users', function (TableBuilder $table) {
            $table->integer('id')->primaryKey();
            $table->string('name', 100);
            $table->string('email')->nullable();
        });
        $db->database->executeMigration($migration);
        $db->database->executeSql('INSERT INTO users (id, name) VALUES (?, ?)', [1, 'Ada']);
        foreach ([[2, null], [1, 'Duplicate'], [null, 'No key']] as $values) {
            try {
                $db->database->executeSql('INSERT INTO users (id, name) VALUES (?, ?)', $values);
                self::fail('Expected a database constraint violation.');
            } catch (\PDOException) {
                self::assertSame(1, (int) $db->database->getDbConnection()->query('SELECT COUNT(*) FROM users')->fetchColumn());
            }
        }
    }

    public function testAlterRenameIndexesDropAndDefaultsExecuteOnExistingData(): void
    {
        $pdo = $this->connection();
        $db = new DbContext($pdo);
        $m = new MigrationBuilder();
        $m->createTable('evolving', function (TableBuilder $t) { $t->integer('id')->primaryKey(); $t->string('name', 60); });
        $db->database->executeMigration($m);
        $db->database->executeSql('INSERT INTO evolving (id,name) VALUES (?,?)', [1,'Ada']);
        $default = "guest'\\name";
        $m = new MigrationBuilder();
        $m->addColumn('evolving', fn (TableBuilder $t) => $t->string('nickname',100)->defaultValue($default))
            ->renameColumn('evolving', 'nickname', 'display_name')
            ->createIndex('evolving', 'ux_evolving_name', ['name'], unique:true);
        $db->database->executeMigration($m);
        self::assertSame($default, $pdo->query('SELECT display_name FROM evolving WHERE id=1')->fetchColumn());
        try { $db->database->executeSql('INSERT INTO evolving (id,name) VALUES (?,?)', [2,'Ada']); self::fail('Unique index must be enforced.'); }
        catch (\PDOException) { self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM evolving')->fetchColumn()); }
        $m = new MigrationBuilder();
        $m->dropIndex('evolving','ux_evolving_name')->dropColumn('evolving','display_name');
        $db->database->executeMigration($m);
        $db->database->executeSql('INSERT INTO evolving (id,name) VALUES (?,?)', [2,'Ada']);
        self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM evolving')->fetchColumn());
        self::assertSame('Ada', $pdo->query('SELECT name FROM evolving WHERE id=1')->fetchColumn());
        $m = new MigrationBuilder();
        $m->dropTable('evolving');
        $db->database->executeMigration($m);
        $this->expectException(\PDOException::class);
        $pdo->query('SELECT * FROM evolving');
    }

    public function testEmptyMigrationDoesNothing(): void
    {
        $db = new DbContext($this->connection());
        self::assertSame('', $db->database->generateMigrationScript(new MigrationBuilder()));
        $db->database->executeMigration(new MigrationBuilder());
        self::assertFalse($db->database->inTransaction());
    }

    public function testMultipleTablesAndDuplicateCreateFailure(): void
    {
        $db = new DbContext($this->connection());
        $migration = new MigrationBuilder();
        $migration->createTable('first_table', fn (TableBuilder $t) => $t->integer('id')->primaryKey());
        $migration->createTable('second_table', fn (TableBuilder $t) => $t->string('name'));
        $db->database->executeMigration($migration);
        foreach (['first_table', 'second_table'] as $table) {
            self::assertSame(0, (int) $db->database->getDbConnection()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
        }
        $this->expectException(\PDOException::class);
        $db->database->executeMigration($migration);
    }
}

final class MigrationUser extends Entity {}
