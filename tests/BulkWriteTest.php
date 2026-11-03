<?php
declare(strict_types=1);

namespace Light\Entity\Tests\BulkWrite;

use Light\Entity\{DbContext, Entity, EntityState, SaveChangesInterceptor, Table};
use Light\Linq\Expr;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

#[Table('orders')]
final class BulkOrder extends Entity {}

final class RecordingPdo extends PDO
{
    public array $prepared = [];
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepared[] = $query;
        return parent::prepare($query, $options);
    }
}

final class BulkWriteTest extends TestCase
{
    private RecordingPdo $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $this->pdo = new RecordingPdo('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE orders(id INTEGER PRIMARY KEY, status TEXT, previous_status TEXT, total REAL, label TEXT GENERATED ALWAYS AS (status || '!') STORED); INSERT INTO orders(id,status,total) VALUES(1,'pending',10),(2,'pending',20),(3,'complete',30)");
        $this->db = new DbContext($this->pdo);
        $this->db->set(BulkOrder::class);
        $this->pdo->prepared = [];
    }

    public function testUpdateAndDeleteEachExecuteOneStatementWithoutSelectingEntities(): void
    {
        $orders = $this->db->set(BulkOrder::class);
        self::assertSame(2, $orders->where(['status' => 'pending'])->executeUpdate(['status' => 'cancelled', 'total' => 1.25]));
        self::assertCount(1, $this->pdo->prepared);
        self::assertStringStartsWith('UPDATE ', $this->pdo->prepared[0]);
        self::assertSame([], $this->db->changeTracker->entries());
        self::assertSame(2, $orders->where(['status' => 'cancelled'])->executeDelete());
        self::assertCount(2, $this->pdo->prepared);
        self::assertStringStartsWith('DELETE FROM ', $this->pdo->prepared[1]);
        self::assertSame([['id'=>3, 'status'=>'complete']], $this->pdo->query('SELECT id,status FROM orders')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testBoundValuesExpressionsNullsAndMultiplePredicates(): void
    {
        $orders = $this->db->set(BulkOrder::class);
        $value = "x'; DELETE FROM orders; --";
        self::assertSame(1, $orders->where(['status'=>'pending'])->where(['id'=>1])->executeUpdate([
            'previous_status'=>Expr::field('status'), 'status'=>$value, 'total'=>null,
        ]));
        self::assertStringNotContainsString($value, $this->pdo->prepared[0]);
        $row = $this->pdo->query('SELECT status,previous_status,total,label FROM orders WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['status'=>$value, 'previous_status'=>'pending', 'total'=>null, 'label'=>$value.'!'], $row);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(0, $orders->where(['id'=>99])->executeUpdate(['status'=>'none']));
        self::assertSame(0, $orders->where(['id'=>99])->executeDelete());
    }

    public function testWritesBypassTrackingSnapshotsAndSaveInterceptors(): void
    {
        $orders = $this->db->set(BulkOrder::class);
        $tracked = $orders->find(1);
        $original = $this->db->entry($tracked)->originalValues;
        $hook = new class extends SaveChangesInterceptor {
            public function savingChanges(DbContext $context): void { throw new \LogicException('Must not invoke save hooks.'); }
        };
        $this->db->addInterceptors($hook);
        self::assertSame(1, $orders->where(['id'=>1])->executeUpdate(['status'=>'cancelled']));
        self::assertSame('pending', $tracked->status);
        self::assertSame($original, $this->db->entry($tracked)->originalValues);
        self::assertSame(EntityState::Unchanged, $this->db->entry($tracked)->state);
        self::assertSame('cancelled', $orders->asNoTracking()->find(1)->status);
        self::assertSame(1, $orders->where(['id'=>1])->executeDelete());
        self::assertSame($tracked, $orders->find(1));
        self::assertNull($orders->asNoTracking()->find(1));
    }

    public function testUsesCallerTransactionAndUnfilteredWritesAffectEntireTable(): void
    {
        $orders = $this->db->set(BulkOrder::class);
        $this->db->database->beginTransaction();
        self::assertSame(3, $orders->executeUpdate(['status'=>'cancelled']));
        self::assertSame(3, $orders->executeDelete());
        self::assertTrue($this->pdo->inTransaction());
        $this->db->database->rollbackTransaction();
        self::assertSame(3, $orders->count());
        self::assertSame('pending', $orders->asNoTracking()->find(1)->status);
    }

    public function testInvalidAssignmentsAndUnsupportedQueriesFailBeforeAnyWrite(): void
    {
        $orders = $this->db->set(BulkOrder::class);
        $operations = [
            fn () => $orders->executeUpdate([]),
            fn () => $orders->executeUpdate(['missing'=>1]),
            fn () => $orders->executeUpdate(['id'=>99]),
            fn () => $orders->executeUpdate(['label'=>'computed']),
            fn () => $orders->executeUpdate(['status'=>['not scalar']]),
            fn () => $orders->executeUpdate(['total'=>Expr::sum(Expr::field('total'))]),
            fn () => $orders->take(1)->executeDelete(),
            fn () => $orders->skip(1)->executeUpdate(['status'=>'bad']),
            fn () => $orders->orderBy(Expr::field('id'))->executeDelete(),
            fn () => $orders->fromSqlRaw('SELECT * FROM orders')->executeDelete(),
            fn () => $orders->include('Anything')->executeUpdate(['status'=>'bad']),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Unsupported write must throw before executing SQL.');
            } catch (\LogicException $e) {
                self::assertSame([], $this->pdo->prepared);
            }
        }
        self::assertSame(3, $orders->count());
    }
}
