<?php

declare(strict_types=1);
namespace Light\Entity\Tests;

use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use Light\Linq\Expression\MethodCallExpression;
use Light\Entity\Sql\{SqlQueryCompiler, SqliteDialect, MySqlDialect, PostgreSqlDialect, SqlServerDialect};
use Light\Entity\SqlQueryProvider;
use PHPUnit\Framework\TestCase;

final class LastOperatorsTest extends TestCase
{
    private function query(): Queryable { return Queryable::from(new InMemoryQueryProvider([]), 'rows'); }
    private function terminal(Queryable $q, string $method, array $args = []): MethodCallExpression
    {
        return new MethodCallExpression($q->expression(), $method, $args);
    }

    public function testEveryDialectReversesEveryOrderingAndLimitsRows(): void
    {
        foreach ([new SqliteDialect(), new MySqlDialect(), new PostgreSqlDialect(), new SqlServerDialect()] as $d) {
            $compiler = new SqlQueryCompiler($d);
            foreach (['last', 'lastOrDefault'] as $method) {
                $q = $this->query()->orderBy(Expr::field('name'))->thenByDescending(Expr::field('id'));
                $c = $compiler->compile($this->terminal($q, $method));
                self::assertStringContainsString('ORDER BY '.$d->quoteIdentifier('name').' DESC, '.$d->quoteIdentifier('id').' ASC', $c->sql);
                self::assertStringContainsString($d instanceof SqlServerDialect ? 'TOP (1)' : 'LIMIT 1', $c->sql);
                self::assertSame($method.'-row', $c->resultMode);
            }
            $c = $compiler->compile($this->terminal($this->query(), 'singleOrDefault', ['default' => false]));
            self::assertStringContainsString($d instanceof SqlServerDialect ? 'TOP (2)' : 'LIMIT 2', $c->sql);
            self::assertFalse($c->defaultValue);
        }
    }

    public function testLastRequiresExplicitOrdering(): void
    {
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($this->terminal($this->query(), 'lastOrDefault'));
    }

    public function testPagedSourceKeepsItsOrderBeforeReverseAndPredicate(): void
    {
        $q = $this->query()->orderBy(Expr::field('id'))->skip(1)->take(2);
        $compiler = new SqlQueryCompiler(new SqliteDialect());
        $c = $compiler->compile($this->terminal($q, 'last'));
        self::assertSame('SELECT * FROM (SELECT * FROM "rows" ORDER BY "id" ASC LIMIT 2 OFFSET 1) AS "__linq_result" ORDER BY "id" DESC LIMIT 1', $c->sql);
        $c = $compiler->compile($this->terminal($q, 'lastOrDefault', ['predicate' => Expr::lt(Expr::field('id'), 3)]));
        self::assertStringContainsString('LIMIT 2 OFFSET 1)', $c->sql);
        self::assertStringEndsWith('WHERE ("id" < :p0) ORDER BY "id" DESC LIMIT 1', $c->sql);
        $scalar = $q->select(Expr::field('id'));
        $c = $compiler->compile($this->terminal($scalar, 'lastOrDefault'));
        self::assertStringEndsWith('ORDER BY "__linq_value" DESC LIMIT 1', $c->sql);
        self::assertSame('lastOrDefault-scalar', $c->resultMode);
    }

    public function testDefaultAndScalarValuesAreDistinguishedWithoutADriver(): void
    {
        foreach (['lastOrDefault', 'singleOrDefault', 'last'] as $method) {
            foreach ([null, false, 0] as $value) {
                $stmt = $this->createMock(\PDOStatement::class);
                $stmt->method('execute')->willReturn(true);
                $stmt->method('fetch')->willReturnOnConsecutiveCalls(['__linq_value' => $value], false);
                $stmt->expects(self::once())->method('closeCursor');
                $pdo = $this->createStub(\PDO::class);
                $pdo->method('prepare')->willReturn($stmt);
                $q = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'rows')->orderBy(Expr::field('id'))->select(Expr::field('id'));
                self::assertSame($value, $q->$method());
            }
        }
        foreach (['lastOrDefault', 'singleOrDefault'] as $method) {
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn(false);
            $stmt->expects(self::once())->method('closeCursor');
            $pdo = $this->createStub(\PDO::class);
            $pdo->method('prepare')->willReturn($stmt);
            $q = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'rows')->orderBy(Expr::field('id'));
            $default = new \stdClass();
            self::assertSame($default, $q->$method(default: $default));
        }
    }

    public function testSingleOrDefaultRejectsMultipleRows(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->expects(self::exactly(2))->method('fetch')->willReturnOnConsecutiveCalls(['id' => 1], ['id' => 2]);
        $stmt->expects(self::once())->method('closeCursor');
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $q = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'rows');
        $this->expectException(\UnexpectedValueException::class);
        $q->singleOrDefault();
    }

    public function testSqliteExecution(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite required.');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE rows(id INTEGER, value INTEGER); INSERT INTO rows VALUES(1,0),(2,5),(3,NULL),(4,9)');
        $q = Queryable::from(new SqlQueryProvider($pdo, new SqliteDialect()), 'rows')->orderBy(Expr::field('id'));
        self::assertSame(4, $q->last()['id']);
        self::assertSame(2, $q->take(2)->last()['id']);
        self::assertSame(3, $q->skip(1)->take(2)->last()['id']);
        self::assertSame(2, $q->take(3)->last(Expr::lt(Expr::field('id'), 3))['id']);
        self::assertNull($q->where(Expr::eq(Expr::field('id'), 3))->select(Expr::field('value'))->lastOrDefault(default: 99));
        self::assertSame('empty', $q->take(0)->lastOrDefault(default: 'empty'));
        self::assertNull($q->take(0)->singleOrDefault());
    }
}
