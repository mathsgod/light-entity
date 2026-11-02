<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use LogicException;
use PDO;
use PDOException;
use Light\Entity\DbContext;
use Light\Entity\EntityMapping;
use Light\Linq\Expr;
use Light\Entity\Sql\SqliteDialect;
use PHPUnit\Framework\TestCase;

final class User
{
    public ?int $id = null;
    public string $name = '';
}

final class DbContextTest extends TestCase
{
    private PDO $pdo;
    private DbContext $db;
    private EntityMapping $mapping;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for database integration tests.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE)');
        $this->mapping = new EntityMapping(User::class, 'users', ['id', 'name']);
        $this->db = $this->context();
    }

    public function testUpdateAttachesDetachedEntityAndDefersWriteUntilSave(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Peter')");
        $user = new User();
        $user->id = 1;
        $user->name = 'Mary';

        $entry = $this->db->update($user);

        self::assertSame($user, $entry->entity);
        self::assertSame(\Light\Entity\EntityState::Modified, $entry->state);
        self::assertSame($user, $this->db->set(User::class)->find(1));
        self::assertSame('Peter', $this->pdo->query('SELECT name FROM users WHERE id = 1')->fetchColumn());
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('Mary', $this->pdo->query('SELECT name FROM users WHERE id = 1')->fetchColumn());
        self::assertSame(\Light\Entity\EntityState::Unchanged, $entry->state);
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testUpdateRequiresAnExistingKey(): void
    {
        $this->expectException(LogicException::class);
        $this->db->update(new User());
    }

    public function testUpdateRejectsAnotherInstanceWithTheSameTrackedKey(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Peter')");
        $this->db->set(User::class)->find(1);
        $user = new User();
        $user->id = 1;
        $user->name = 'Mary';

        $this->expectException(LogicException::class);
        $this->db->update($user);
    }

    public function testInsertIsDeferredAndGeneratedIdIsAssigned(): void
    {
        $user = new User();
        $user->name = 'Peter';
        $users = $this->db->set(User::class);
        $users->add($user);
        self::assertSame(0, $users->count());
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame(1, $user->id);
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testArrayWhereComposesWithExistingExpressions(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Peter'), (2, 'Mary'), (3, 'Paul')");
        $users = $this->db->set(User::class)
            ->where(['_or' => [['name' => 'Peter'], ['name' => 'Mary']]])
            ->where(Expr::gte(Expr::field('id'), 2))
            ->toArray();

        self::assertCount(1, $users);
        self::assertSame('Mary', $users[0]->name);
    }

    public function testAddRangeDefersInsertsUntilSaveChanges(): void
    {
        $peter = new User();
        $peter->name = 'Peter';
        $mary = new User();
        $mary->name = 'Mary';
        $users = $this->db->set(User::class);

        $users->addRange([$peter, $mary]);

        self::assertSame(0, $users->count());
        self::assertNull($peter->id);
        self::assertNull($mary->id);
        self::assertSame(2, $this->db->saveChanges());
        self::assertNotNull($peter->id);
        self::assertNotNull($mary->id);
        self::assertNotSame($peter->id, $mary->id);
        self::assertSame($peter, $users->find($peter->id));
        self::assertSame($mary, $users->find($mary->id));
        $loaded = $this->context()->set(User::class)->orderBy(Expr::field('id'))->toArray();
        self::assertSame(['Peter', 'Mary'], array_map(fn (User $user) => $user->name, $loaded));
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testAddRangeAcceptsGenerator(): void
    {
        $entities = (static function (): \Generator {
            foreach (['Peter', 'Mary'] as $name) {
                $user = new User();
                $user->name = $name;
                yield $user;
            }
        })();

        $this->db->set(User::class)->addRange($entities);

        self::assertSame(2, $this->db->saveChanges());
        self::assertSame(2, $this->db->set(User::class)->count());
    }

    public function testAddRangeWithEmptyCollectionDoesNothing(): void
    {
        $this->db->set(User::class)->addRange([]);

        self::assertSame(0, $this->db->saveChanges());
        self::assertSame(0, $this->db->set(User::class)->count());
    }

    public function testIdentityMapReusesTrackedEntity(): void
    {
        $user = $this->insertUser();
        $users = $this->db->set(User::class);
        self::assertSame($user, $users->find($user->id));
        self::assertSame($user, $users->first());
    }

    public function testTrackedChangesArePersisted(): void
    {
        $user = $this->insertUser();
        $user->name = 'Updated';
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('Updated', $this->pdo->query('SELECT name FROM users')->fetchColumn());
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testQueriesHydrateEntitiesAndSupportTerminals(): void
    {
        $this->insertUser();
        $users = $this->context()->set(User::class);
        $loaded = $users->where(Expr::eq(Expr::field('id'), 1))->first();
        self::assertInstanceOf(User::class, $loaded);
        self::assertSame('Peter', $loaded->name);
        self::assertNull($users->find(404));
        self::assertTrue($users->any());
        self::assertCount(1, $users->take(1)->toArray());
    }

    public function testFailedSaveRollsBackAllWritesAndCanBeRetried(): void
    {
        $this->insertUser();
        $a = new User(); $a->name = 'Duplicate';
        $b = new User(); $b->name = 'Duplicate';
        $users = $this->db->set(User::class);
        $users->addRange([$a, $b]);
        try {
            $this->db->saveChanges();
            self::fail('Expected unique constraint failure.');
        } catch (PDOException) {
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertNull($a->id);
        self::assertNull($b->id);
        self::assertSame(1, $users->count());
        $b->name = 'Different';
        self::assertSame(2, $this->db->saveChanges());
        self::assertSame(3, $users->count());
    }

    public function testChangingTrackedPrimaryKeyIsRejected(): void
    {
        $user = $this->insertUser();
        $user->id = 99;
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Changing a tracked primary key');
        $this->db->saveChanges();
    }

    public function testRemoveDeletesAndDetachesEntity(): void
    {
        $user = $this->insertUser();
        $users = $this->db->set(User::class);
        $users->remove($user);
        self::assertSame(1, $this->db->saveChanges());
        self::assertNull($users->find($user->id));
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testRemovingPendingInsertCancelsIt(): void
    {
        $users = $this->db->set(User::class);
        $user = new User();
        $users->add($user);
        $users->remove($user);
        self::assertSame(0, $this->db->saveChanges());
        self::assertSame(0, $users->count());
    }

    public function testCallerTransactionIsRejectedAndRemainsIntact(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->db->saveChanges();
            self::fail('Expected caller transaction rejection.');
        } catch (LogicException $error) {
            self::assertStringContainsString('manages its own transaction', $error->getMessage());
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            $this->pdo->rollBack();
        }
    }

    private function context(): DbContext
    {
        return new DbContext($this->pdo, new SqliteDialect(), [$this->mapping]);
    }

    private function insertUser(): User
    {
        $user = new User();
        $user->name = 'Peter';
        $this->db->set(User::class)->add($user);
        $this->db->saveChanges();
        return $user;
    }
}
