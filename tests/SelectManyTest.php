<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use Light\Linq\Expressions\MethodCallExpression;
use Light\Entity\Sql\{SqlQueryCompiler, MySqlDialect, PostgreSqlDialect, SqliteDialect, SqlServerDialect};
use Light\Entity\SqlQueryProvider;
use PHPUnit\Framework\TestCase;

final class SelectManyTest extends TestCase
{
    private function queries(): array
    {
        $p = new InMemoryQueryProvider([]);
        return [Queryable::from($p, 'customers'), Queryable::from($p, 'orders')];
    }

    public function testCrossJoinAndConditionalJoinCompileAcrossDialects(): void
    {
        [$customers, $orders] = $this->queries();
        $selector = Expr::projection(['customer' => 'outer.id', 'order' => 'inner.id']);
        foreach ([new MySqlDialect(), new PostgreSqlDialect(), new SqliteDialect(), new SqlServerDialect()] as $d) {
            $compiler = new SqlQueryCompiler($d);
            $cross = $compiler->compile($customers->selectMany($orders, $selector)->expression());
            self::assertStringContainsString(' CROSS JOIN ', $cross->sql);
            self::assertStringNotContainsString(' ON ', $cross->sql);
            $matched = $customers->where(Expr::gt(Expr::field('id'), 0))->selectMany(
                $orders->where(Expr::gt(Expr::field('id'), 9)), $selector,
                Expr::both(Expr::eq(Expr::field('outer.id'), Expr::field('inner.customerId')),
                    Expr::gt(Expr::field('inner.id'), 10)));
            $c = $compiler->compile($matched->expression());
            self::assertStringContainsString(' INNER JOIN ', $c->sql);
            self::assertStringContainsString($d->quoteIdentifier('outer.id').' = '.$d->quoteIdentifier('inner.customerId'), $c->sql);
            self::assertStringContainsString($d->quoteIdentifier('inner.id').' > :p2', $c->sql);
            self::assertSame(['p0' => 0, 'p1' => 9, 'p2' => 10], $c->parameters);
        }
    }

    public function testNamedProjectionCanBeFilteredOrderedAndAggregated(): void
    {
        [$customers, $orders] = $this->queries();
        $q = $customers->selectMany($orders, Expr::projection(['order' => 'inner.id']),
            Expr::eq(Expr::field('outer.id'), Expr::field('inner.customerId')))
            ->where(Expr::gt(Expr::field('order'), 10))->orderByDescending(Expr::field('order'))->take(2);
        $compiler = new SqlQueryCompiler(new SqliteDialect());
        $c = $compiler->compile($q->expression());
        self::assertStringContainsString('FROM (SELECT "inner"."id" AS "order"', $c->sql);
        self::assertStringContainsString('WHERE ("order" > :p0) ORDER BY "order" DESC LIMIT 2', $c->sql);
        $sum = $compiler->compile(new MethodCallExpression($q->expression(), 'sum', ['selector' => Expr::field('order')]));
        self::assertStringStartsWith('SELECT COALESCE(SUM("order"), 0)', $sum->sql);
    }

    public function testSourcePaginationStaysInsideEachJoinInput(): void
    {
        [$customers, $orders] = $this->queries();
        $q = $customers->orderBy(Expr::field('id'))->take(1)->selectMany(
            $orders->orderByDescending(Expr::field('id'))->take(2), Expr::field('inner.id'));
        $c = (new SqlQueryCompiler(new SqliteDialect()))->compile($q->expression());
        self::assertStringContainsString('FROM "customers" ORDER BY "id" ASC LIMIT 1) AS "outer"', $c->sql);
        self::assertStringContainsString('FROM "orders" ORDER BY "id" DESC LIMIT 2) AS "inner"', $c->sql);
        self::assertSame('scalar-list', $c->resultMode);
    }

    public function testScalarSqlSourcesAreRejected(): void
    {
        [$customers, $orders] = $this->queries();
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($customers
            ->selectMany($orders->select(Expr::field('id')), Expr::field('inner.id'))->expression());
    }

    public function testDbSetReturnsAnUntrackedQueryable(): void
    {
        $context = $this->createStub(\Light\Entity\DbContext::class);
        $mapping = new \Light\Entity\EntityMapping(SelectManyEntity::class, 'customers', ['id']);
        [$customers, $orders] = $this->queries();
        $outer = new \Light\Entity\DbSet($context, $mapping, $customers);
        $inner = new \Light\Entity\DbSet($context, $mapping, $orders);
        $q = $outer->selectMany($inner, Expr::field('inner.id'));
        self::assertInstanceOf(Queryable::class, $q);
        self::assertSame('selectMany', $q->expression()->method);
    }

    public function testSqliteExecution(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite required.');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE customers(id INTEGER); CREATE TABLE orders(id INTEGER, customerId INTEGER); INSERT INTO customers VALUES(1),(2),(NULL); INSERT INTO orders VALUES(10,1),(11,1),(12,NULL)');
        $p = new SqlQueryProvider($pdo, new SqliteDialect());
        $customers = Queryable::from($p, 'customers');
        $orders = Queryable::from($p, 'orders');
        self::assertSame(9, $customers->selectMany($orders, Expr::field('inner.id'))->count());
        $q = $customers->selectMany($orders, Expr::projection(['customer' => 'outer.id', 'order' => 'inner.id']),
            Expr::eq(Expr::field('outer.id'), Expr::field('inner.customerId')));
        self::assertSame([['customer' => 1, 'order' => 10], ['customer' => 1, 'order' => 11]], $q->orderBy(Expr::field('order'))->toArray());
        self::assertSame(21, $q->sum(Expr::field('order')));
        self::assertSame([], $customers->selectMany($orders->take(0), Expr::field('inner.id'))->toArray());
    }
}

final class SelectManyEntity
{
    public int $id;
}
