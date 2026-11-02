<?php
declare(strict_types=1);
namespace Light\Entity\Tests;
use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use Light\Entity\Sql\{SqlQueryCompiler, SqliteDialect, MySqlDialect, PostgreSqlDialect, SqlServerDialect};
use Light\Entity\SqlQueryProvider;
use PHPUnit\Framework\TestCase;
final class GroupingTest extends TestCase
{
    private function query(): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider([]), 'orders');
    }
    public function testGroupingAcrossDialects(): void
    {
        $query = $this->query()->where(Expr::gt(Expr::field('amount'), 0))
            ->groupBy(Expr::fields('customer', 'country'))
            ->where(Expr::gte(Expr::count(), 5))
            ->select(Expr::projection(['customer' => Expr::key('customer'),
                'count' => Expr::count(), 'total' => Expr::sum(Expr::field('amount'))]));
        foreach ([new SqliteDialect(), new MySqlDialect(), new PostgreSqlDialect(), new SqlServerDialect()] as $dialect) {
            $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
            self::assertStringContainsString(' GROUP BY ', $compiled->sql);
            self::assertStringContainsString(' HAVING (COUNT(*) >= :p1)', $compiled->sql);
            self::assertStringContainsString('SUM(', $compiled->sql);
            self::assertSame(['p0' => 0, 'p1' => 5], $compiled->parameters);
        }
    }
    public function testProjectedHavingAndDistinctComposition(): void
    {
        $compiler = new SqlQueryCompiler(new SqliteDialect());
        $query = $this->query()->groupBy(Expr::field('customer'))
            ->select(Expr::projection(['n' => Expr::count()]))->where(Expr::gte(Expr::field('n'), 2));
        $sql = $compiler->compile($query->expression())->sql;
        self::assertStringContainsString('FROM (SELECT COUNT(*) AS "n"', $sql);
        self::assertStringContainsString('WHERE ("n" >= :p0)', $sql);
        $query = $this->query()->select(Expr::fields('customer'))->take(2)->distinct();
        self::assertSame('SELECT DISTINCT * FROM (SELECT "customer" AS "customer" FROM "orders" LIMIT 2) AS "__linq_result"',
            $compiler->compile($query->expression())->sql);
        self::assertStringStartsWith('SELECT COUNT(*)', $compiler->compile(new \Light\Linq\Expression\MethodCallExpression($query->expression(), 'count'))->sql);
    }
    public function testScalarDistinctAfterPaginationPreservesScalarResult(): void
    {
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile($this->query()
            ->select(Expr::field('customer'))->take(2)->distinct()->expression());
        self::assertSame('scalar-list', $compiled->resultMode);
        self::assertSame('SELECT DISTINCT "__linq_value" AS "__linq_value" FROM (SELECT "customer" AS "__linq_value" FROM "orders" LIMIT 2) AS "__linq_result"', $compiled->sql);
    }
    public function testDistinctBeforeProjectionKeepsOriginalDistinctRows(): void
    {
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile($this->query()
            ->distinct()->select(Expr::field('customer'))->expression());
        self::assertStringContainsString('FROM (SELECT DISTINCT * FROM "orders")', $compiled->sql);
    }
    public function testAggregatesOutsideGroupingAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($this->query()->select(Expr::count())->expression());
    }
    public function testNestedAggregatesAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($this->query()->groupBy(Expr::field('customer'))
            ->select(Expr::sum(Expr::count()))->expression());
    }
    public function testCountAndAnyCanConsumeGroupsWithoutProjection(): void
    {
        $query = $this->query()->groupBy(Expr::field('customer'));
        foreach (['count', 'any'] as $terminal) {
            $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile(
                new \Light\Linq\Expression\MethodCallExpression($query->expression(), $terminal));
            self::assertSame($terminal, $compiled->resultMode);
            self::assertStringStartsWith('SELECT COUNT(*)', $compiled->sql);
            self::assertStringContainsString('GROUP BY "customer"', $compiled->sql);
        }
    }
    public function testScalarAggregateResultMode(): void
    {
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile($this->query()
            ->groupBy(Expr::field('customer'))->select(Expr::count())->expression());
        self::assertSame('scalar-list', $compiled->resultMode);
    }
    public function testInvalidGroupedFieldIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($this->query()
            ->groupBy(Expr::field('customer'))->select(Expr::field('amount'))->expression());
    }
    public function testFullGroupsAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($this->query()->groupBy(Expr::field('customer'))->expression());
    }
    public function testExecutesGroupedAndDistinctQueries(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is not installed.');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE orders (customer INTEGER, amount INTEGER)');
        $pdo->exec('INSERT INTO orders VALUES (1,10),(1,20),(2,NULL)');
        $query = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'orders');
        self::assertSame([['customer' => 1, 'total' => 30]], $query->groupBy(Expr::field('customer'))
            ->where(Expr::gte(Expr::count(), 2))->select(Expr::projection([
                'customer' => Expr::key(), 'total' => Expr::sum(Expr::field('amount')),
            ]))->toArray());
        self::assertSame([1, 2], $query->select(Expr::field('customer'))->distinct()->toArray());
        self::assertSame(2, $query->select(Expr::field('customer'))->distinct()->count());
    }
}
