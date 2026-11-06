<?php
declare(strict_types=1);
namespace Light\Entity\Tests;
use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use Light\Linq\Expressions\MethodCallExpression;
use Light\Entity\Sql\{SqlQueryCompiler, SqliteDialect, MySqlDialect, PostgreSqlDialect, SqlServerDialect};
use Light\Entity\SqlQueryProvider;
use PHPUnit\Framework\TestCase;
final class SumTest extends TestCase
{
    private function query(): Queryable { return Queryable::from(new InMemoryQueryProvider([]), 'orders'); }
    public function testCompilesDirectSumForEveryDialect(): void
    {
        foreach ([new SqliteDialect(), new MySqlDialect(), new PostgreSqlDialect(), new SqlServerDialect()] as $d) {
            $q = $this->query()->where(Expr::gt(Expr::field('amount'), 5));
            $c = (new SqlQueryCompiler($d))->compile(new MethodCallExpression($q->expression(), 'sum', ['selector' => Expr::field('amount')]));
            self::assertSame('SELECT COALESCE(SUM('.$d->quoteIdentifier('amount').'), 0) AS '
                .$d->quoteIdentifier('__linq_sum').' FROM '.$d->quoteIdentifier('orders')
                .' WHERE ('.$d->quoteIdentifier('amount').' > :p0)', $c->sql);
            self::assertSame(['p0' => 5], $c->parameters);
            self::assertSame('sum', $c->resultMode);
        }
    }
    public function testPagingAndDistinctRemainInsideSumSource(): void
    {
        $compiler = new SqlQueryCompiler(new SqliteDialect());
        $q = $this->query()->orderByDescending(Expr::field('id'))->take(2);
        $c = $compiler->compile(new MethodCallExpression($q->expression(), 'sum', ['selector' => Expr::field('amount')]));
        self::assertSame('SELECT COALESCE(SUM("amount"), 0) AS "__linq_sum" FROM (SELECT * FROM "orders" ORDER BY "id" DESC LIMIT 2) AS "__linq_source"', $c->sql);
        $q = $this->query()->select(Expr::fields('amount'))->distinct();
        $c = $compiler->compile(new MethodCallExpression($q->expression(), 'sum', ['selector' => Expr::field('amount')]));
        self::assertStringContainsString('FROM (SELECT DISTINCT "amount" AS "amount"', $c->sql);
    }
    public function testProviderPreservesDecimalPrecisionAndNumericTypes(): void
    {
        foreach ([0, 30, 1.25, '12345678901234567890.12'] as $value) {
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->expects(self::once())->method('fetchColumn')->willReturn($value);
            $statement->expects(self::once())->method('closeCursor');
            $pdo = $this->createStub(\PDO::class);
            $pdo->method('prepare')->willReturn($statement);
            $q = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'orders');
            self::assertSame($value, $q->sum(Expr::field('amount')));
        }
    }
    public function testNestedAggregateSelectorIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile(new MethodCallExpression(
            $this->query()->expression(), 'sum', ['selector' => Expr::sum(Expr::field('amount'))]));
    }
    public function testCompilesOtherAggregatesForAllDialects(): void
    {
        foreach ([new SqliteDialect(), new MySqlDialect(), new PostgreSqlDialect(), new SqlServerDialect()] as $d) {
            foreach (['average' => 'AVG', 'min' => 'MIN', 'max' => 'MAX'] as $method => $function) {
                foreach ([$this->query(), $this->query()->orderBy(Expr::field('id'))->take(2)] as $q) {
                    $compiled = (new SqlQueryCompiler($d))->compile(new MethodCallExpression($q->expression(), $method, ['selector' => Expr::field('amount')]));
                    $operand = $d->quoteIdentifier('amount');
                    if ($method === 'average' && $d instanceof SqlServerDialect) $operand = 'CAST('.$operand.' AS FLOAT)';
                    self::assertStringContainsString($function.'('.$operand.')', $compiled->sql);
                    self::assertStringNotContainsString('COALESCE', $compiled->sql);
                    self::assertSame($method, $compiled->resultMode);
                }
            }
        }
    }

    public function testProviderPreservesNullableAndStringAggregateResults(): void
    {
        foreach (['average' => [null, 15.5, '15.5000'], 'min' => [null, false, 'Ada', 10], 'max' => [null, 'Zoe', 21]] as $method => $values) {
            foreach ($values as $value) {
                $statement = $this->createMock(\PDOStatement::class);
                $statement->method('execute')->willReturn(true);
                $statement->expects(self::once())->method('fetch')->with(\PDO::FETCH_NUM)->willReturn([$value]);
                $statement->expects(self::once())->method('closeCursor');
                $pdo = $this->createStub(\PDO::class);
                $pdo->method('prepare')->willReturn($statement);
                $q = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'orders');
                self::assertSame($value, $q->$method(Expr::field('amount')));
            }
        }
    }

    public function testSqliteExecution(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite required.');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE orders(id INTEGER, amount INTEGER); INSERT INTO orders VALUES(1,10),(2,20),(3,NULL)');
        $q = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'orders');
        self::assertSame(30, $q->sum(Expr::field('amount')));
        self::assertEquals(15, $q->average(Expr::field('amount')));
        self::assertSame(10, $q->min(Expr::field('amount')));
        self::assertSame(20, $q->max(Expr::field('amount')));
        foreach (['average', 'min', 'max'] as $method) {
            self::assertNull($q->take(0)->$method(Expr::field('amount')));
            self::assertNull($q->where(Expr::eq(Expr::field('id'), 3))->$method(Expr::field('amount')));
        }

        self::assertSame(0, $q->take(0)->sum(Expr::field('amount')));
        self::assertSame(0, $q->where(Expr::eq(Expr::field('id'), 3))->sum(Expr::field('amount')));
        self::assertSame(20, $q->orderByDescending(Expr::field('id'))->take(2)->sum(Expr::field('amount')));
    }
}
