<?php
declare(strict_types=1);
namespace Light\Entity\Tests;
use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use Light\Linq\Expressions\MethodCallExpression;
use Light\Entity\Sql\{SqlQueryCompiler, SqliteDialect, MySqlDialect, PostgreSqlDialect, SqlServerDialect};
use Light\Entity\SqlQueryProvider;
use PHPUnit\Framework\TestCase;
final class QueryOperatorsTest extends TestCase
{
    public function testNonpositiveSkipAllowsFilteringAndOrderingForEveryDialect(): void
    {
        foreach ([new SqliteDialect(), new MySqlDialect(), new PostgreSqlDialect(), new SqlServerDialect()] as $dialect) {
            foreach ([0, -1] as $count) {
                $query = $this->query()->skip($count)->where(Expr::gt(Expr::field('id'), 1))->orderBy(Expr::field('id'));
                $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
                self::assertStringContainsString('ORDER BY', $compiled->sql);
                self::assertStringNotContainsString('OFFSET', $compiled->sql);
                self::assertSame(['p0' => 1], $compiled->parameters);
            }
        }
    }
    public function testLinq130OrderingAndPagedReverseForEveryDialect(): void
    {
        foreach ([new SqliteDialect(), new MySqlDialect(), new PostgreSqlDialect(), new SqlServerDialect()] as $dialect) {
            $compiler = new SqlQueryCompiler($dialect);
            $scalar = $this->query()->select(Expr::field('id'));
            self::assertStringContainsString($dialect->quoteIdentifier('id').' ASC', $compiler->compile($scalar->order()->expression())->sql);
            self::assertStringContainsString($dialect->quoteIdentifier('id').' DESC', $compiler->compile($scalar->orderDescending()->expression())->sql);
            $page = $this->query()->orderBy(Expr::field('id'))->take(2);
            $compiled = $compiler->compile($page->reverse()->expression());
            self::assertStringContainsString('FROM (SELECT ', $compiled->sql);
            self::assertStringContainsString($dialect->quoteIdentifier('id').' ASC', $compiled->sql);
            self::assertStringContainsString($dialect->quoteIdentifier('id').' DESC', $compiled->sql);
            $compiled = $compiler->compile($scalar->order()->take(2)->reverse()->expression());
            self::assertStringContainsString($dialect->quoteIdentifier('__linq_value').' DESC', $compiled->sql);
        }
    }

    public function testIdentityOrderingRejectsEntityRows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('scalar select()');
        (new SqlQueryCompiler(new SqliteDialect()))->compile($this->query()->order()->expression());
    }

    public function testReverseRequiresExplicitOrdering(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit orderBy()');
        (new SqlQueryCompiler(new SqliteDialect()))->compile($this->query()->reverse()->expression());
    }

    private function query(): Queryable { return Queryable::from(new InMemoryQueryProvider([]), 'users'); }
    private function terminal(Queryable $q, string $method, array $args = []): MethodCallExpression
    {
        return new MethodCallExpression($q->expression(), $method, $args);
    }
    public function testEveryDialectCompilesTerminalsAndOrdering(): void
    {
        foreach ([new SqliteDialect(), new MySqlDialect(), new PostgreSqlDialect(), new SqlServerDialect()] as $dialect) {
            $compiler = new SqlQueryCompiler($dialect);
            $q = $this->query()->orderBy(Expr::field('country'))->thenByDescending(Expr::field('name'));
            $compiled = $compiler->compile($q->expression());
            self::assertStringContainsString($dialect->quoteIdentifier('country').' ASC, '.$dialect->quoteIdentifier('name').' DESC', $compiled->sql);
            $compiled = $compiler->compile($q->orderBy(Expr::field('id'))->expression());
            self::assertStringNotContainsString($dialect->quoteIdentifier('country').' ASC', $compiled->sql);
            $default = new \stdClass();
            $compiled = $compiler->compile($this->terminal($q, 'firstOrDefault', ['default' => $default]));
            self::assertSame('firstOrDefault-row', $compiled->resultMode);
            self::assertSame($default, $compiled->defaultValue);
            self::assertStringContainsString($dialect instanceof SqlServerDialect ? 'TOP (1)' : 'LIMIT 1', $compiled->sql);
            $compiled = $compiler->compile($this->terminal($q, 'single'));
            self::assertSame('single-row', $compiled->resultMode);
            self::assertStringContainsString($dialect instanceof SqlServerDialect ? 'TOP (2)' : 'LIMIT 2', $compiled->sql);
            $compiled = $compiler->compile($this->terminal($q, 'all', ['predicate' => Expr::gt(Expr::field('id'), 0)]));
            self::assertStringContainsString('NOT EXISTS', $compiled->sql);
            self::assertStringContainsString('THEN 0 ELSE 1 END = 1', $compiled->sql);
            self::assertSame(['p0' => 0], $compiled->parameters);
            $compiled = $compiler->compile($this->terminal($q->select(Expr::field('id')), 'contains', ['value' => null]));
            self::assertStringContainsString(' IS NULL)', $compiled->sql);
            self::assertSame('boolean', $compiled->resultMode);
        }
    }
    public function testSinglePreservesTakeAndPredicateRunsAfterPaging(): void
    {
        $compiler = new SqlQueryCompiler(new SqliteDialect());
        $compiled = $compiler->compile($this->terminal($this->query()->take(1), 'single'));
        self::assertStringEndsWith('LIMIT 1', $compiled->sql);
        $compiled = $compiler->compile($this->terminal($this->query()->take(2), 'firstOrDefault', ['predicate' => Expr::eq(Expr::field('id'), 3)]));
        self::assertStringContainsString('FROM (SELECT * FROM "users" LIMIT 2)', $compiled->sql);
        self::assertStringContainsString('WHERE ("id" = :p0) LIMIT 1', $compiled->sql);
    }
    public function testTerminalPredicateKeepsPagedOrderingAndProjectionAliases(): void
    {
        $q = $this->query()->orderByDescending(Expr::field('id'))->take(3)
            ->select(Expr::projection(['key' => Expr::field('id')]));
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile($this->terminal($q, 'firstOrDefault', [
            'predicate' => Expr::gt(Expr::field('key'), 0),
        ]));
        self::assertStringContainsString('ORDER BY "id" DESC LIMIT 3)', $compiled->sql);
        self::assertStringEndsWith('WHERE ("key" > :p0) ORDER BY "key" DESC LIMIT 1', $compiled->sql);
    }
    public function testContainsBindsItsValueAfterSourceParameters(): void
    {
        $q = $this->query()->where(Expr::gt(Expr::field('id'), 0))->select(Expr::field('name'))->distinct();
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile($this->terminal($q, 'contains', ['value' => "O'Reilly"]));
        self::assertSame(['p0' => 0, 'p1' => "O'Reilly"], $compiled->parameters);
        self::assertStringNotContainsString("O'Reilly", $compiled->sql);
        self::assertStringContainsString('"__linq_value" = :p1', $compiled->sql);
    }
    public function testContainsRejectsRows(): void
    {
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($this->terminal($this->query(), 'contains', ['value' => 1]));
    }
    public function testTerminalFetchingDistinguishesEmptyNullAndFalseWithoutADriver(): void
    {
        foreach ([null, false, 0, ''] as $value) {
            foreach (['single', 'firstOrDefault'] as $method) {
                $statement = $this->createMock(\PDOStatement::class);
                $statement->method('execute')->willReturn(true);
                $statement->method('fetch')->willReturnOnConsecutiveCalls(['__linq_value' => $value], false);
                $statement->expects(self::once())->method('closeCursor');
                $pdo = $this->createStub(\PDO::class);
                $pdo->method('prepare')->willReturn($statement);
                $q = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'users')->select(Expr::field('active'));
                self::assertSame($value, $method === 'single' ? $q->single() : $q->firstOrDefault(default: 'missing'));
            }
        }
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn(false);
        $statement->expects(self::exactly(2))->method('closeCursor');
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $q = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'users');
        $default = new \stdClass();
        self::assertSame($default, $q->firstOrDefault(default: $default));
        $this->expectException(\UnderflowException::class);
        $q->single();
    }
    public function testSingleRejectsMultipleRowsBeforeReturningResult(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->expects(self::exactly(2))->method('fetch')->willReturnOnConsecutiveCalls(['id' => 1], ['id' => 2]);
        $statement->expects(self::once())->method('closeCursor');
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $q = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'users');
        $this->expectException(\UnexpectedValueException::class);
        $q->single();
    }
    public function testDbSetSingleMaterializesAndLoadsIncludes(): void
    {
        $mapping = new \Light\Entity\EntityMapping(QueryOperatorUser::class, 'users', ['id']);
        $entity = new QueryOperatorUser();
        $entity->id = 1;
        $context = $this->createMock(\Light\Entity\DbContext::class);
        $context->expects(self::once())->method('validateIncludes')->with($mapping, ['orders']);
        $context->expects(self::once())->method('materialize')
            ->with($mapping, ['id' => 1], \Light\Entity\QueryTrackingBehavior::NoTracking)->willReturn($entity);
        $context->expects(self::once())->method('loadIncludes')
            ->with($mapping, [$entity], ['orders'], \Light\Entity\QueryTrackingBehavior::NoTracking);
        $q = Queryable::from(new InMemoryQueryProvider(['users' => [['id' => 1]]]), 'users');
        $set = new \Light\Entity\DbSet($context, $mapping, $q, includes: ['orders'],
            trackingBehavior: \Light\Entity\QueryTrackingBehavior::NoTracking);
        self::assertSame($entity, $set->single());
    }
    public function testDbSetDefaultAndLoadedQueriesDoNotMaterializeRows(): void
    {
        $mapping = new \Light\Entity\EntityMapping(QueryOperatorUser::class, 'users', ['id']);
        $context = $this->createMock(\Light\Entity\DbContext::class);
        $context->method('validateIncludes');
        $context->method('loadIncludes');
        $context->expects(self::never())->method('materialize');
        $q = Queryable::from(new InMemoryQueryProvider(['users' => []]), 'users');
        $empty = new \Light\Entity\DbSet($context, $mapping, $q,
            trackingBehavior: \Light\Entity\QueryTrackingBehavior::NoTracking);
        $default = new QueryOperatorUser();
        self::assertSame($default, $empty->firstOrDefault(default: $default));
        $entity = new QueryOperatorUser();
        $entity->id = 2;
        $loaded = new \Light\Entity\DbSet($context, $mapping, $q, loaded: [$entity],
            trackingBehavior: \Light\Entity\QueryTrackingBehavior::NoTracking);
        self::assertSame($entity, $loaded->single(Expr::eq(Expr::field('id'), 2)));
        self::assertTrue($loaded->all(Expr::gt(Expr::field('id'), 0)));
        self::assertNull($loaded->firstOrDefault(Expr::eq(Expr::field('id'), 3)));
    }
    public function testRealSqliteExecution(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite required.');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER, country TEXT, name TEXT, active INTEGER)');
        $pdo->exec("INSERT INTO users VALUES (1,'HK','Zoe',1),(2,'UK','Ada',0),(3,'HK','Ada',NULL)");
        $q = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'users');
        self::assertSame([3,1,2], $q->orderBy(Expr::field('country'))->thenBy(Expr::field('name'))->select(Expr::field('id'))->toArray());
        self::assertNull($q->take(0)->firstOrDefault());
        self::assertSame('none', $q->take(0)->firstOrDefault(default: 'none'));
        self::assertNull($q->where(Expr::eq(Expr::field('id'), 3))->select(Expr::field('active'))->firstOrDefault(default: 'none'));
        self::assertSame(2, $q->single(Expr::eq(Expr::field('id'), 2))['id']);
        self::assertTrue($q->take(0)->all(Expr::gt(Expr::field('id'), 100)));
        self::assertFalse($q->all(Expr::eq(Expr::field('active'), 1)));
        self::assertFalse($q->where(Expr::eq(Expr::field('id'), 3))->all(Expr::eq(Expr::field('active'), 1)));
        self::assertTrue($q->select(Expr::field('active'))->contains(null));
        self::assertFalse($q->take(1)->select(Expr::field('id'))->contains(2));
        self::assertSame(1, $q->take(1)->single()['id']);
        try { $q->take(0)->single(); self::fail('Expected empty exception.'); } catch (\UnderflowException) {}
        $this->expectException(\UnexpectedValueException::class);
        $q->single();
    }
}

final class QueryOperatorUser
{
    public int $id = 0;
}
