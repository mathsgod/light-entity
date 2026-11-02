<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Interception;

use PDO;
use Light\Entity\{DbContext, Entity, EntityState, SaveChangesInterceptor, Table};
use PHPUnit\Framework\TestCase;

#[Table('users')]
final class SaveUser extends Entity {}

final class SaveChangesInterceptorTest extends TestCase
{
    private PDO $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE users (user_id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, created_at TEXT, updated_at TEXT, deleted_at TEXT, label TEXT GENERATED ALWAYS AS (name || '!') STORED); CREATE TABLE audit (message TEXT); INSERT INTO users(user_id,name) VALUES(1,'Mary'),(2,'John')");
        $this->db = new DbContext($this->pdo);
    }

    public function testHooksObserveDetectedStatesAndTheirChangesAreSavedIncludingSoftDelete(): void
    {
        $hook = new class extends SaveChangesInterceptor {
            public array $before = [];
            public array $after = [];
            public int $result = -1;
            public function savingChanges(DbContext $context): void
            {
                foreach ($context->changeTracker->entries(SaveUser::class) as $entry) {
                    $this->before[] = $entry->state;
                    if ($entry->state === EntityState::Added) { $entry->entity->created_at = 'now'; }
                    if ($entry->state === EntityState::Modified) { $entry->entity->updated_at = 'now'; }
                    if ($entry->state === EntityState::Deleted) {
                        $entry->state = EntityState::Modified;
                        $entry->entity->deleted_at = 'now';
                    }
                }
            }
            public function savedChanges(DbContext $context, int $result): void
            {
                $this->result = $result;
                foreach ($context->changeTracker->entries() as $entry) {
                    $this->after[] = [$entry->state, $entry->entity->user_id, $entry->originalValues];
                }
            }
        };
        $this->db->addInterceptors($hook);
        $users = $this->db->set(SaveUser::class);
        $mary = $users->find(1);
        $mary->name = 'Mary Updated';
        $john = $users->find(2);
        $users->remove($john);
        $new = new SaveUser();
        $new->name = 'Ada';
        $users->add($new);
        self::assertSame(3, $this->db->saveChanges());
        self::assertSame([EntityState::Modified, EntityState::Deleted, EntityState::Added], $hook->before);
        self::assertSame(3, $hook->result);
        foreach ($hook->after as [$state, $id, $original]) {
            self::assertSame(EntityState::Unchanged, $state);
            self::assertNotNull($id);
            self::assertSame($id, $original['user_id']);
        }
        self::assertSame('now', $new->created_at);
        self::assertSame('now', $mary->updated_at);
        self::assertSame('now', $john->deleted_at);
        self::assertSame('Ada!', $new->label);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testHooksRunInRegistrationOrderAndCanAddEntitiesForThisSave(): void
    {
        $log = new \ArrayObject();
        $first = new class($log) extends SaveChangesInterceptor {
            public function __construct(private \ArrayObject $log) {}
            public function savingChanges(DbContext $context): void
            {
                $this->log[] = 'first.before';
                $user = new SaveUser();
                $user->name = 'Added in hook';
                $context->set(SaveUser::class)->add($user);
            }
            public function savedChanges(DbContext $context, int $result): void { $this->log[] = 'first.after'; }
        };
        $second = new class($log) extends SaveChangesInterceptor {
            public function __construct(private \ArrayObject $log) {}
            public function savingChanges(DbContext $context): void
            {
                $this->log[] = 'second.before';
                foreach ($context->changeTracker->entries() as $entry) { $entry->entity->created_at = 'second'; }
            }
            public function savedChanges(DbContext $context, int $result): void { $this->log[] = 'second.after'; }
        };
        $db = new DbContext($this->pdo, interceptors: [$first, $second]);
        self::assertSame(1, $db->saveChanges());
        self::assertSame(['first.before','second.before','first.after','second.after'], $log->getArrayCopy());
        self::assertSame('second', $this->pdo->query("SELECT created_at FROM users WHERE name = 'Added in hook'")->fetchColumn());
    }

    public function testSqlFailureRollsBackWithoutAcceptingSnapshotsAndCanBeRetried(): void
    {
        $hook = new class extends SaveChangesInterceptor {
            public ?\Throwable $error = null;
            public bool $transactionOnFailure = true;
            public int $successes = 0;
            public function savingChanges(DbContext $context): void
            {
                $context->database->executeSql('INSERT INTO audit VALUES (?)', ['saving']);
            }
            public function saveChangesFailed(DbContext $context, \Throwable $exception): void
            {
                $this->error = $exception;
                $this->transactionOnFailure = $context->database->inTransaction();
            }
            public function savedChanges(DbContext $context, int $result): void { ++$this->successes; }
        };
        $this->db->addInterceptors($hook);
        $users = $this->db->set(SaveUser::class);
        $mary = $users->find(1);
        $mary->name = 'Changed';
        $new = new SaveUser();
        $new->name = 'John';
        $users->add($new);
        try { $this->db->saveChanges(); self::fail('Expected unique constraint failure'); }
        catch (\PDOException $error) { self::assertSame($error, $hook->error); }
        self::assertFalse($hook->transactionOnFailure);
        self::assertSame(0, $hook->successes);
        self::assertNull($new->user_id);
        self::assertSame('Mary', $this->db->entry($mary)->originalValues['name']);
        self::assertSame(EntityState::Modified, $this->db->entry($mary)->state);
        self::assertSame(EntityState::Added, $this->db->entry($new)->state);
        self::assertSame('Mary', $this->pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn());
        $new->name = 'Unique';
        self::assertSame(2, $this->db->saveChanges());
        self::assertSame(1, $hook->successes);
        self::assertSame(EntityState::Unchanged, $this->db->entry($mary)->state);
        self::assertNotNull($new->user_id);
    }

    public function testSavingHookExceptionRollsBackItsSqlAndNotifiesFailure(): void
    {
        $hook = new class extends SaveChangesInterceptor {
            public bool $throw = true;
            public ?\Throwable $error = null;
            public function savingChanges(DbContext $context): void
            {
                $context->database->executeSql('INSERT INTO audit VALUES (?)', ['before']);
                if ($this->throw) { throw new \RuntimeException('Rejected before writing entities'); }
            }
            public function saveChangesFailed(DbContext $context, \Throwable $exception): void { $this->error = $exception; }
        };
        $this->db->addInterceptors($hook);
        $user = $this->db->set(SaveUser::class)->find(1);
        $user->name = 'Changed';
        try { $this->db->saveChanges(); self::fail('Expected hook exception'); }
        catch (\RuntimeException $error) { self::assertSame($error, $hook->error); }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn());
        self::assertSame('Mary', $this->db->entry($user)->originalValues['name']);
        $hook->throw = false;
        self::assertSame(1, $this->db->saveChanges());
    }

    public function testFailureObserverExceptionsDoNotHideOriginalErrorOrSkipOtherObservers(): void
    {
        $first = new class extends SaveChangesInterceptor {
            public function savingChanges(DbContext $context): void { throw new \RuntimeException('Original failure'); }
            public function saveChangesFailed(DbContext $context, \Throwable $exception): void { throw new \LogicException('Observer failure'); }
        };
        $second = new class extends SaveChangesInterceptor {
            public ?\Throwable $error = null;
            public function saveChangesFailed(DbContext $context, \Throwable $exception): void { $this->error = $exception; }
        };
        $this->db->addInterceptors($first, $second);
        try { $this->db->saveChanges(); self::fail('Expected original failure'); }
        catch (\RuntimeException $error) {
            self::assertSame('Original failure', $error->getMessage());
            self::assertSame($error, $second->error);
        }
    }

    public function testSavedHookExceptionDoesNotRollbackCommittedWriteOrReportSqlFailure(): void
    {
        $hook = new class extends SaveChangesInterceptor {
            public bool $throw = true;
            public int $failures = 0;
            public function savedChanges(DbContext $context, int $result): void
            {
                if ($this->throw) { throw new \RuntimeException('After commit'); }
            }
            public function saveChangesFailed(DbContext $context, \Throwable $exception): void { ++$this->failures; }
        };
        $this->db->addInterceptors($hook);
        $user = $this->db->set(SaveUser::class)->find(1);
        $user->name = 'Committed';
        try { $this->db->saveChanges(); self::fail('Expected saved hook exception'); }
        catch (\RuntimeException $error) { self::assertSame('After commit', $error->getMessage()); }
        self::assertSame(0, $hook->failures);
        self::assertSame('Committed', $this->pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn());
        self::assertSame(EntityState::Unchanged, $this->db->entry($user)->state);
        self::assertFalse($this->pdo->inTransaction());
        $hook->throw = false;
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testRecursiveSaveIsRejectedAndGuardIsResetAfterFailure(): void
    {
        $hook = new class extends SaveChangesInterceptor {
            public bool $recurse = true;
            public function savingChanges(DbContext $context): void { if ($this->recurse) { $context->saveChanges(); } }
        };
        $this->db->addInterceptors($hook);
        try { $this->db->saveChanges(); self::fail('Expected recursion rejection'); }
        catch (\LogicException $error) { self::assertStringContainsString('Recursive', $error->getMessage()); }
        $hook->recurse = false;
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testNoChangeSaveStillRunsHooksAndStateDetachmentPreventsWrite(): void
    {
        $hook = new class extends SaveChangesInterceptor {
            public array $results = [];
            public function savingChanges(DbContext $context): void
            {
                foreach ($context->changeTracker->entries() as $entry) { $entry->state = EntityState::Detached; }
            }
            public function savedChanges(DbContext $context, int $result): void { $this->results[] = $result; }
        };
        $this->db->addInterceptors($hook);
        $user = $this->db->set(SaveUser::class)->find(1);
        $user->name = 'Ignored';
        self::assertSame(0, $this->db->saveChanges());
        self::assertSame([0], $hook->results);
        self::assertSame('Mary', $this->pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn());
    }

    public function testOuterTransactionUsesSavepointAndSavedHookDoesNotMeanCommit(): void
    {
        $hook = new class extends SaveChangesInterceptor {
            public array $transactionStates = [];
            public function savedChanges(DbContext $context, int $result): void { $this->transactionStates[] = $context->database->inTransaction(); }
        };
        $this->db->addInterceptors($hook);
        $this->db->database->beginTransaction();
        try {
            $user = $this->db->set(SaveUser::class)->find(1);
            $user->name = 'Outer';
            self::assertSame(1, $this->db->saveChanges());
            self::assertSame([true], $hook->transactionStates);
            $user->name = 'John';
            try { $this->db->saveChanges(); self::fail('Expected constraint failure'); }
            catch (\PDOException) { self::assertTrue($this->pdo->inTransaction()); }
            self::assertSame('Outer', $this->pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn());
        } finally { $this->db->database->rollbackTransaction(); }
        self::assertSame('Mary', $this->pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn());
    }
}
