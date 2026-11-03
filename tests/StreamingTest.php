<?php
declare(strict_types=1);

namespace Light\Entity\Tests\Streaming;

use Light\Entity\{DbContext, Entity, Table};
use Light\Linq\Expr;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

#[Table('users')]
final class StreamUser extends Entity {}

final class ObservedStatement extends PDOStatement
{
    public static int $fetches = 0;
    public static int $closed = 0;
    protected function __construct() {}

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        ++self::$fetches;
        return parent::fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        throw new \LogicException('Streaming must not call fetchAll().');
    }

    public function closeCursor(): bool
    {
        ++self::$closed;
        return parent::closeCursor();
    }
}

final class StreamingTest extends TestCase
{
    private PDO $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite required.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES(1,'Ada'),(2,'Bob'),(3,'Cara')");
        $this->db = new DbContext($this->pdo);
        $this->db->set(StreamUser::class); // Discover metadata before instrumenting queries.
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ObservedStatement::class]);
        ObservedStatement::$fetches = ObservedStatement::$closed = 0;
    }

    public function testReadsOnlyRequestedRowsAndClosesAbandonedIterator(): void
    {
        $query = $this->db->set(StreamUser::class)->asNoTracking()->orderBy(Expr::field('id'));
        $iterator = $query->getIterator();
        self::assertSame(0, ObservedStatement::$fetches);
        $iterator->rewind();
        self::assertSame('Ada', $iterator->current()->name);
        self::assertSame(1, ObservedStatement::$fetches);
        self::assertSame([], $this->db->changeTracker->entries());
        unset($iterator);
        self::assertSame(1, ObservedStatement::$closed);
        self::assertSame(['Ada', 'Bob', 'Cara'], array_map(fn ($u) => $u->name, $query->toArray()));
        self::assertSame(2, ObservedStatement::$closed);
    }

    public function testBreakAndConsumerExceptionReleaseTemporaryIterator(): void
    {
        $query = $this->db->set(StreamUser::class)->asNoTracking();
        foreach ($query as $user) { break; }
        self::assertSame(1, ObservedStatement::$fetches);
        self::assertSame(1, ObservedStatement::$closed);
        try {
            foreach ($query as $user) { throw new \RuntimeException('stop'); }
        } catch (\RuntimeException $e) {
            self::assertSame('stop', $e->getMessage());
        }
        self::assertSame(2, ObservedStatement::$closed);
    }

    public function testHydrationExceptionAlsoClosesCursor(): void
    {
        $query = $this->db->set(StreamUser::class)->asNoTracking()->fromSqlRaw('SELECT id FROM users');
        try {
            $query->toArray();
            self::fail('Missing mapped column must fail hydration.');
        } catch (\LogicException $e) {
            self::assertSame(1, ObservedStatement::$closed);
        }
    }
}
