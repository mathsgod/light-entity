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
}
