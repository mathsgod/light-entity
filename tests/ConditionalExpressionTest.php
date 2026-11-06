<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, Entity, Table};
use PDO;
use Light\Entity\Sql\{SqlDialect, SqlQueryCompiler, MySqlDialect, PostgreSqlDialect, SqlServerDialect, SqliteDialect};
use Light\Linq\{Expr, Queryable, InMemoryQueryProvider};
use Light\Linq\Expressions\ProjectionExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[Table('conditional_orders')]
final class ConditionalOrder extends Entity {}

final class ConditionalExpressionTest extends TestCase
{
    public function testEntityProjectionAndScalarResultsInSqlite(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE conditional_orders (id INTEGER PRIMARY KEY, name TEXT, total INT)');
        $pdo->exec("INSERT INTO conditional_orders VALUES (1, NULL, 0), (2, '', 20), (3, 'Ada', 100)");
        $orders = (new DbContext($pdo))->set(ConditionalOrder::class);
        self::assertSame(['Guest', '', 'Ada'], $orders->select(fn($o) => $o->name ?? 'Guest')->toArray());
        self::assertSame('small', $orders->select(fn($o) => $o->total >= 100 ? 'large' : 'small')->first());
        self::assertSame([['customer' => 'Guest', 'size' => 'small'], ['customer' => '', 'size' => 'small'], ['customer' => 'Ada', 'size' => 'large']],
            $orders->select(fn($o) => ['customer' => $o->name ?? 'Guest', 'size' => $o->total >= 100 ? 'large' : 'small'])->toArray());
        self::assertSame([1], $orders->where(fn($o) => ($o->name ?? 'Guest') == 'Guest')
            ->select(fn($o) => $o->id)->toArray());
    }

    public static function dialects(): iterable
    {
        yield 'mysql' => [new MySqlDialect()];
        yield 'postgresql' => [new PostgreSqlDialect()];
        yield 'sqlserver' => [new SqlServerDialect()];
        yield 'sqlite' => [new SqliteDialect()];
    }

    private function orders(): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider([]), 'orders');
    }

    #[DataProvider('dialects')]
    public function testNamedProjectionCompilesNestedBranchesAndParameters(SqlDialect $dialect): void
    {
        $query = $this->orders()->select(fn($o) => [
            'customer' => $o->name ?? 'Guest',
            'size' => $o->total >= 100 ? 'large' : ($o->name ?? 'small'),
        ]);
        $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
        self::assertSame('SELECT COALESCE('.$dialect->quoteIdentifier('name').', :p0) AS '.$dialect->quoteIdentifier('customer')
            .', (CASE WHEN ('.$dialect->quoteIdentifier('total').' >= :p1) THEN :p2 ELSE COALESCE('
            .$dialect->quoteIdentifier('name').', :p3) END) AS '.$dialect->quoteIdentifier('size')
            .' FROM '.$dialect->quoteIdentifier('orders'), $compiled->sql);
        self::assertSame(['p0' => 'Guest', 'p1' => 100, 'p2' => 'large', 'p3' => 'small'], $compiled->parameters);
        self::assertSame('rows', $compiled->resultMode);
    }

    #[DataProvider('dialects')]
    public function testScalarSelectionsRetainShapeForTerminalAndSetOperations(SqlDialect $dialect): void
    {
        $compiler = new SqlQueryCompiler($dialect);
        $orders = $this->orders();
        $coalesce = $orders->select(fn($o) => $o->name ?? 'Guest');
        self::assertSame('scalar-list', $compiler->compile($coalesce->expression())->resultMode);
        $conditional = $orders->select(fn($o) => $o->total >= 100 ? 'large' : 'small');
        self::assertSame('scalar-list', $compiler->compile($conditional->expression())->resultMode);
        self::assertSame('scalar-list', $compiler->compile($coalesce->union($conditional)->expression())->resultMode);
        $first = new \Light\Linq\Expressions\MethodCallExpression($conditional->expression(), 'first');
        self::assertSame('first-scalar', $compiler->compile($first)->resultMode);
        $contains = new \Light\Linq\Expressions\MethodCallExpression($coalesce->expression(), 'contains', ['value' => 'Guest']);
        self::assertStringContainsString('COALESCE(', $compiler->compile($contains)->sql);
    }

    #[DataProvider('dialects')]
    public function testGroupedNodesResolveKeysAndValidateHiddenFields(SqlDialect $dialect): void
    {
        $query = $this->orders()->groupBy(Expr::field('name'))->select(new ProjectionExpression([
            'name' => Expr::coalesce(Expr::key(), 'Guest'),
            'size' => Expr::condition(Expr::gte(Expr::count(), 2), 'many', 'one'),
        ]));
        self::assertStringContainsString('CASE WHEN (COUNT(*) >= ', (new SqlQueryCompiler($dialect))->compile($query->expression())->sql);
        $this->expectException(\LogicException::class);
        $invalid = $this->orders()->groupBy(Expr::field('name'))->select(Expr::coalesce(Expr::field('total'), 0));
        (new SqlQueryCompiler($dialect))->compile($invalid->expression());
    }
}
