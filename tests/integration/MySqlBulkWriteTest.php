<?php
declare(strict_types=1);
namespace Light\Entity\Tests\Integration;

use Light\Entity\{DbContext, Entity, Table};
use Light\Entity\Tests\Support\MySqlDatabase;
use Light\Linq\Expr;
use PDO;
use PHPUnit\Framework\TestCase;

#[Table('bulk_orders')]
final class MySqlBulkOrder extends Entity {}

final class MySqlBulkWriteTest extends TestCase
{
    use MySqlDatabase;

    public function testParameterizedBulkWritesBypassTrackingAndUseCallerTransaction(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE bulk_orders(id INT PRIMARY KEY, status VARCHAR(100), previous_status VARCHAR(100), total DECIMAL(10,2), label VARCHAR(110) GENERATED ALWAYS AS (CONCAT(status,\'!\')) STORED)');
        $pdo->exec("INSERT INTO bulk_orders(id,status,total) VALUES(1,'pending',10),(2,'pending',20),(3,'complete',30)");
        $db = new DbContext($pdo);
        $orders = $db->set(MySqlBulkOrder::class);
        $tracked = $orders->find(1);
        $status = "cancelled'; DROP TABLE bulk_orders; --";
        self::assertSame(2, $orders->where(['status'=>'pending'])->executeUpdate(['previous_status'=>Expr::field('status'), 'status'=>$status, 'total'=>12.5]));
        self::assertSame('pending', $tracked->status);
        $read = $orders->asNoTracking()->find(1);
        self::assertSame($status, $read->status);
        self::assertSame('pending', $read->previous_status);
        self::assertSame('12.50', $read->total);
        self::assertSame($status.'!', $read->label);
        self::assertCount(1, $db->changeTracker->entries());
        $db->database->beginTransaction();
        self::assertSame(2, $orders->where(['status'=>$status])->executeDelete());
        self::assertTrue($pdo->inTransaction());
        $db->database->rollbackTransaction();
        self::assertSame(3, $orders->count());
        self::assertSame(2, $orders->where(['status'=>$status])->executeDelete());
        self::assertSame(0, $orders->where(['id'=>99])->executeDelete());
        self::assertSame(0, $orders->where(['id'=>99])->executeUpdate(['status'=>null]));
        self::assertSame(1, $orders->executeUpdate(['status'=>null]));
        self::assertSame(1, $orders->executeDelete());
        self::assertSame(0, $orders->count());
    }

    public function testCompositeKeyPredicatesTargetOnlyMatchingRow(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE bulk_orders(a INT, b INT, status VARCHAR(30), PRIMARY KEY(a,b))');
        $pdo->exec("INSERT INTO bulk_orders VALUES(1,1,'pending'),(1,2,'pending')");
        $db = new DbContext($pdo);
        $orders = $db->set(MySqlBulkOrder::class);
        self::assertSame(1, $orders->where(['a'=>1,'b'=>2])->executeUpdate(['status'=>'complete']));
        self::assertSame('pending', $orders->asNoTracking()->find(['a'=>1,'b'=>1])->status);
        self::assertSame(1, $orders->where(['a'=>1,'b'=>2])->executeDelete());
        self::assertSame(1, $orders->count());
        self::assertSame([], $db->changeTracker->entries());
    }
}
