<?php

declare(strict_types=1);

namespace PhpEntity\Tests\Integration;

use PDO;
use PhpEntity\{DbContext, Entity, EntityState, SaveChangesInterceptor, Table};
use PhpEntity\Tests\Support\MySqlDatabase;
use PHPUnit\Framework\TestCase;

#[Table('users')]
final class MySqlTrackedUser extends Entity {}

final class MySqlChangeTrackerTest extends TestCase
{
    use MySqlDatabase;

    private function trackingDatabase(): PDO
    {
        $pdo = $this->connection();
        $pdo->exec("CREATE TABLE users (user_id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL UNIQUE, updated_at DATETIME NULL, deleted_at DATETIME NULL, label VARCHAR(80) GENERATED ALWAYS AS (CONCAT(name,'!')) STORED)");
        $pdo->exec("INSERT INTO users(name) VALUES ('Mary'),('John')");
        return $pdo;
    }

    public function testEntriesInterceptorsGeneratedValuesAndSoftDelete(): void
    {
        $pdo = $this->trackingDatabase();
        $hook = new class extends SaveChangesInterceptor {
            public array $states = [];
            public array $savedStates = [];
            public int $result = -1;
            public function savingChanges(DbContext $context): void
            {
                foreach ($context->changeTracker->entries(MySqlTrackedUser::class) as $entry) {
                    $this->states[] = $entry->state;
                    if ($entry->state === EntityState::Modified) { $entry->entity->updated_at = '2026-11-02 12:00:00'; }
                    if ($entry->state === EntityState::Deleted) {
                        $entry->state = EntityState::Modified;
                        $entry->entity->deleted_at = '2026-11-02 12:00:00';
                    }
                }
            }
            public function savedChanges(DbContext $context, int $result): void
            {
                $this->result = $result;
                foreach ($context->changeTracker->entries() as $entry) { $this->savedStates[] = $entry->state; }
            }
        };
        $db = new DbContext($pdo, interceptors: [$hook]);
        $users = $db->set(MySqlTrackedUser::class);
        $mary = $users->find(1);
        $entry = $db->entry($mary);
        self::assertSame(EntityState::Unchanged, $entry->state);
        $mary->name = 'Mary Updated';
        self::assertSame(EntityState::Modified, $entry->state);
        self::assertSame('Mary', $entry->originalValues['name']);
        $john = $users->find(2);
        $users->remove($john);
        $new = new MySqlTrackedUser();
        $new->name = 'Ada';
        self::assertSame(EntityState::Detached, $db->entry($new)->state);
        $db->entry($new)->state = EntityState::Added;
        self::assertSame(3, $db->saveChanges());
        self::assertSame([EntityState::Modified, EntityState::Deleted, EntityState::Added], $hook->states);
        self::assertSame([EntityState::Unchanged, EntityState::Unchanged, EntityState::Unchanged], $hook->savedStates);
        self::assertSame(3, $hook->result);
        self::assertSame(3, $new->user_id);
        self::assertSame('Ada!', $new->label);
        self::assertSame('2026-11-02 12:00:00', $mary->updated_at);
        self::assertSame('2026-11-02 12:00:00', $john->deleted_at);
        self::assertSame('Mary Updated', $entry->originalValues['name']);
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testFailureRollsBackSnapshotsAndGeneratedKeysThenAllowsRetry(): void
    {
        $pdo = $this->trackingDatabase();
        $hook = new class extends SaveChangesInterceptor {
            public ?\Throwable $error = null;
            public function saveChangesFailed(DbContext $context, \Throwable $exception): void { $this->error = $exception; }
        };
        $db = new DbContext($pdo);
        $db->addInterceptors($hook);
        $users = $db->set(MySqlTrackedUser::class);
        $user = $users->find(1);
        $user->name = 'Changed';
        $new = new MySqlTrackedUser();
        $new->name = 'John';
        $users->add($new);
        try { $db->saveChanges(); self::fail('Expected duplicate name'); }
        catch (\PDOException $error) { self::assertSame($error, $hook->error); }
        self::assertFalse($pdo->inTransaction());
        self::assertNull($new->user_id);
        self::assertSame(EntityState::Added, $db->entry($new)->state);
        self::assertSame(EntityState::Modified, $db->entry($user)->state);
        self::assertSame('Mary', $db->entry($user)->originalValues['name']);
        self::assertSame('Mary', $pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn());
        $new->name = 'Unique';
        self::assertSame(2, $db->saveChanges());
        self::assertNotNull($new->user_id);
        self::assertSame(EntityState::Unchanged, $db->entry($new)->state);
    }

    public function testSavepointAndPostSaveNotificationLeaveOuterTransactionOpen(): void
    {
        $pdo = $this->trackingDatabase();
        $hook = new class extends SaveChangesInterceptor {
            public array $transactions = [];
            public function savedChanges(DbContext $context, int $result): void { $this->transactions[] = $context->database->inTransaction(); }
        };
        $db = new DbContext($pdo, interceptors: [$hook]);
        $db->database->beginTransaction();
        try {
            $user = $db->set(MySqlTrackedUser::class)->find(1);
            $user->name = 'Outer';
            self::assertSame(1, $db->saveChanges());
            self::assertSame([true], $hook->transactions);
            $user->name = 'John';
            try { $db->saveChanges(); self::fail('Expected duplicate name'); }
            catch (\PDOException) { self::assertTrue($pdo->inTransaction()); }
            self::assertSame('Outer', $pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn());
        } finally { $db->database->rollbackTransaction(); }
        self::assertSame('Mary', $pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn());
    }
}
