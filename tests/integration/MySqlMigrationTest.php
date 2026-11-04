<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Integration;

use Light\Entity\Tests\Support\MySqlDatabase;
use Light\Entity\DbContext;
use Light\Entity\Migrations\MigrationBuilder;
use Light\Entity\Migrations\TableBuilder;
use Light\Entity\Tests\MigrationExecutionTest;

final class MySqlMigrationTest extends MigrationExecutionTest
{
    use MySqlDatabase;

    public function testDdlDoesNotImplicitlyCommitAnExistingTransaction(): void
    {
        $pdo = $this->connection();
        $db = new DbContext($pdo);
        $pdo->exec('CREATE TABLE existing (id INT PRIMARY KEY)');
        $db->database->beginTransaction();
        $pdo->exec('INSERT INTO existing VALUES (1)');
        $migration = new MigrationBuilder();
        $migration->createTable('users', fn (TableBuilder $t) => $t->integer('id'));
        try {
            $db->database->executeMigration($migration);
            self::fail('Expected MySQL transaction guard.');
        } catch (\LogicException) {
            self::assertTrue($pdo->inTransaction());
        } finally {
            $db->database->rollbackTransaction();
        }
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM existing')->fetchColumn());
    }
    public function testAlterColumnReplacesDefinitionAndPreservesExistingValues(): void
    {
        $pdo = $this->connection();
        $db = new DbContext($pdo);
        $m = new MigrationBuilder();
        $m->createTable('profiles', function (TableBuilder $t) { $t->integer('id')->primaryKey(); $t->string('name', 20); });
        $db->database->executeMigration($m);
        $pdo->exec("INSERT INTO profiles VALUES(1,'Ada')");
        $m = new MigrationBuilder();
        $m->alterColumn('profiles', fn (TableBuilder $t) => $t->string('name', 80)->nullable()->defaultValue('預設'));
        $db->database->executeMigration($m);
        self::assertSame('Ada', $pdo->query('SELECT name FROM profiles WHERE id=1')->fetchColumn());
        $pdo->exec('INSERT INTO profiles(id) VALUES(2)');
        self::assertSame('預設', $pdo->query('SELECT name FROM profiles WHERE id=2')->fetchColumn());
        $pdo->exec('INSERT INTO profiles VALUES(3,NULL)');
        $column = (new \Light\Entity\Schema\SchemaReader($pdo))->columns('profiles')[1];
        self::assertTrue($column->nullable);
        self::assertSame('varchar(80)', $column->type);
        $m = new MigrationBuilder();
        $m->alterColumn('profiles', fn (TableBuilder $t) => $t->string('name', 100)->nullable());
        $db->database->executeMigration($m);
        $pdo->exec('INSERT INTO profiles(id) VALUES(4)');
        self::assertNull($pdo->query('SELECT name FROM profiles WHERE id=4')->fetchColumn());
    }

    public function testCompositeForeignKeyEnforcesCascadesAndCanBeDropped(): void
    {
        $pdo = $this->connection();
        $db = new DbContext($pdo);
        $m = new MigrationBuilder();
        $m->createTable('parents', function (TableBuilder $t) {
            $t->integer('tenant')->primaryKey(); $t->integer('id')->primaryKey();
        })->createTable('children', function (TableBuilder $t) {
            $t->integer('id')->primaryKey(); $t->integer('tenant'); $t->integer('parent_id');
        })->addForeignKey('children', 'fk_parent', ['tenant','parent_id'], 'parents', ['tenant','id'], onDelete:'cascade', onUpdate:'cascade');
        $db->database->executeMigration($m);
        $pdo->exec('INSERT INTO parents VALUES(1,10)');
        $pdo->exec('INSERT INTO children VALUES(1,1,10)');
        try { $pdo->exec('INSERT INTO children VALUES(2,1,99)'); self::fail('Foreign key must reject missing parents.'); }
        catch (\PDOException) { self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM children')->fetchColumn()); }
        $pdo->exec('UPDATE parents SET id=11 WHERE tenant=1 AND id=10');
        self::assertSame(11, (int)$pdo->query('SELECT parent_id FROM children')->fetchColumn());
        $pdo->exec('DELETE FROM parents WHERE tenant=1 AND id=11');
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM children')->fetchColumn());
        $m = new MigrationBuilder();
        $m->dropForeignKey('children','fk_parent');
        $db->database->executeMigration($m);
        $pdo->exec('INSERT INTO children VALUES(3,1,99)');
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM children')->fetchColumn());
        $m = new MigrationBuilder();
        $m->dropTable('children')->dropTable('parents');
        $db->database->executeMigration($m);
    }
}
