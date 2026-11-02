<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use LogicException;
use PDO;
use PDOException;
use Light\Entity\DatabaseFacade;
use Light\Entity\DbContext;
use Light\Entity\Entity;
use Light\Entity\Table;
use PHPUnit\Framework\TestCase;

#[Table('users')]
final class FacadeUser extends Entity {}

final class DatabaseFacadeTest extends TestCase
{
    private PDO $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for database integration tests.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, active INTEGER DEFAULT 1, note TEXT NULL)');
        $this->db = new DbContext($this->pdo);
    }

    public function testFacadeUsesContextConnection(): void
    {
        self::assertInstanceOf(DatabaseFacade::class, $this->db->database);
        self::assertSame($this->pdo, $this->db->database->getDbConnection());
        self::assertFalse($this->db->database->inTransaction());
    }

    public function testExecuteSqlBindsPositionalValuesAndReturnsAffectedRows(): void
    {
        $name = "Peter'); DROP TABLE users; --";
        self::assertSame(1, $this->db->database->executeSql(
            'INSERT INTO users (name, active, note) VALUES (?, ?, ?)', [$name, false, null],
        ));
        $row = $this->pdo->query('SELECT name, active, note FROM users')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['name' => $name, 'active' => 0, 'note' => null], $row);
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(0, $this->db->database->executeSql('DELETE FROM users WHERE id = ?', [404]));
    }

    public function testExecuteSqlSupportsNamedParameters(): void
    {
        self::assertSame(1, $this->db->database->executeSql(
            'INSERT INTO users (name, active) VALUES (:name, :active)', ['name' => 'Peter', ':active' => true],
        ));
        self::assertSame(1, $this->db->database->executeSql(
            'UPDATE users SET name = :name WHERE id = :id', ['name' => 'Mary', 'id' => 1],
        ));
        self::assertSame('Mary', $this->pdo->query('SELECT name FROM users')->fetchColumn());
    }

    public function testRawSqlDoesNotRefreshTrackedEntities(): void
    {
        $user = $this->newUser('Peter');
        $this->db->set(FacadeUser::class)->add($user);
        $this->db->saveChanges();
        $this->db->database->executeSql('UPDATE users SET name = ? WHERE id = ?', ['Mary', $user->id]);
        self::assertSame('Peter', $user->name);
        self::assertSame('Mary', (new DbContext($this->pdo))->set(FacadeUser::class)->find($user->id)->name);
    }

    public function testCommitPersistsMultipleSavesAndRawSqlTogether(): void
    {
        $database = $this->db->database;
        $database->beginTransaction();
        $users = $this->db->set(FacadeUser::class);
        $peter = $this->newUser('Peter');
        $users->add($peter);
        self::assertSame(1, $this->db->saveChanges());
        self::assertTrue($database->inTransaction());
        self::assertSame(0, $this->db->saveChanges());
        $users->add($this->newUser('Mary'));
        self::assertSame(1, $this->db->saveChanges());
        $database->executeSql('UPDATE users SET name = ? WHERE id = ?', ['Updated', $peter->id]);
        $database->commitTransaction();
        self::assertFalse($database->inTransaction());
        $rows = $this->pdo->query('SELECT name FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['Updated', 'Mary'], $rows);
    }

    public function testRollbackUndoesMultipleSavesAndRawSql(): void
    {
        $database = $this->db->database;
        $database->beginTransaction();
        $user = $this->newUser('Peter');
        $this->db->set(FacadeUser::class)->add($user);
        $this->db->saveChanges();
        $database->executeSql('INSERT INTO users (name) VALUES (?)', ['Mary']);
        $user->name = 'Updated';
        $this->db->saveChanges();
        $database->rollbackTransaction();
        self::assertFalse($database->inTransaction());
        self::assertSame(0, (new DbContext($this->pdo))->set(FacadeUser::class)->count());
        // Like accepted SaveChanges state, rollback does not rewind PHP objects.
        self::assertSame('Updated', $user->name);
        self::assertNotNull($user->id);
    }

    public function testFailedSaveRollsBackToSavepointAndCanBeRetried(): void
    {
        $database = $this->db->database;
        $database->beginTransaction();
        $users = $this->db->set(FacadeUser::class);
        $users->add($this->newUser('Earlier save'));
        $this->db->saveChanges();
        $database->executeSql('INSERT INTO users (name) VALUES (?)', ['Earlier SQL']);
        $a = $this->newUser('Duplicate');
        $b = $this->newUser('Duplicate');
        $users->addRange([$a, $b]);
        try {
            $this->db->saveChanges();
            self::fail('Expected unique constraint failure.');
        } catch (PDOException) {
            self::assertTrue($database->inTransaction());
        }
        self::assertNull($a->id);
        self::assertNull($b->id);
        self::assertSame(2, $users->count());
        $b->name = 'Different';
        self::assertSame(2, $this->db->saveChanges());
        $database->commitTransaction();
        self::assertSame(4, (new DbContext($this->pdo))->set(FacadeUser::class)->count());
    }

    public function testRollbackCanFollowFailedSave(): void
    {
        $database = $this->db->database;
        $database->beginTransaction();
        $database->executeSql('INSERT INTO users (name) VALUES (?)', ['Earlier SQL']);
        $users = $this->db->set(FacadeUser::class);
        $users->addRange([$this->newUser('Duplicate'), $this->newUser('Duplicate')]);
        try {
            $this->db->saveChanges();
            self::fail('Expected unique constraint failure.');
        } catch (PDOException) {
            $database->rollbackTransaction();
        }
        self::assertFalse($database->inTransaction());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testNestedBeginIsRejectedWithoutEndingTransaction(): void
    {
        $database = $this->db->database;
        $database->beginTransaction();
        try {
            $database->beginTransaction();
            self::fail('Expected nested transaction rejection.');
        } catch (LogicException) {
            self::assertTrue($database->inTransaction());
        } finally {
            $database->rollbackTransaction();
        }
    }

    public function testCommitWithoutFacadeTransactionIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->db->database->commitTransaction();
    }

    public function testRollbackWithoutFacadeTransactionIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->db->database->rollbackTransaction();
    }

    public function testFacadeCannotCommitUnownedTransaction(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->db->database->commitTransaction();
            self::fail('Expected transaction ownership rejection.');
        } catch (LogicException) {
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testNewFacadeTransactionCanStartAfterCompletion(): void
    {
        $database = $this->db->database;
        $database->beginTransaction();
        $database->commitTransaction();
        $database->beginTransaction();
        $database->executeSql('INSERT INTO users (name) VALUES (?)', ['Peter']);
        $database->rollbackTransaction();
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    private function newUser(string $name): FacadeUser
    {
        $user = new FacadeUser();
        $user->name = $name;
        return $user;
    }
}
