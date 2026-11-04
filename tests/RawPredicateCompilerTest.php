<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\Sql\{MySqlDialect, PostgreSqlDialect, RawSqlPredicateExpression, SqlDialect, SqliteDialect, SqlQueryCompiler, SqlServerDialect};
use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RawPredicateCompilerTest extends TestCase
{
    public static function dialects(): iterable
    {
        yield [new MySqlDialect()]; yield [new SqliteDialect()];
        yield [new PostgreSqlDialect()]; yield [new SqlServerDialect()];
    }

    #[DataProvider('dialects')]
    public function testRawPredicatesUseSharedParameterAllocationAndPreserveQuotes(SqlDialect $dialect): void
    {
        $query = Queryable::from(new InMemoryQueryProvider([]),'users')
            ->where(Expr::eq(Expr::field('active'),1))
            ->where(new RawSqlPredicateExpression("name = :p0 OR name = :p0 /* ? :ignored */ -- end", ['p0'=>'Ada']))
            ->where(new RawSqlPredicateExpression("id > ? AND name <> '?'", [2]));
        $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
        self::assertSame(['p0'=>1,'p1'=>'Ada','p2'=>'Ada','p3'=>2], $compiled->parameters);
        self::assertStringContainsString("(name = :p1 OR name = :p2 /* ? :ignored */ -- end\n)", $compiled->sql);
        self::assertStringContainsString("(id > :p3 AND name <> '?'\n)", $compiled->sql);
    }

    public static function invalidConditions(): iterable
    {
        yield ['', []]; yield ["\0", []]; yield ['-- comment only', []];
        yield ['/* comment only */', []]; yield ['id = ?; DELETE FROM users', [1]];
        yield ['id = ?)', [1]]; yield ['(id = ?', [1]]; yield ["name = 'unterminated", []];
        yield ['id = ? /* unfinished', [1]]; yield ['id = ?', []]; yield ['id = :id', []];
        yield ['id = 1', [9]]; yield ['id = :id OR id = ?', ['id'=>1]];
        yield ['id = :id', ['id'=>1, ':id'=>2]];
        yield ['id = ?', [[1]]]; yield ['1 = 1 /*! OR 1=1 */', []];
    }

    #[DataProvider('invalidConditions')]
    public function testInvalidFragmentsFailBeforeExecution(string $sql, array $parameters): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $query = Queryable::from(new InMemoryQueryProvider([]),'users')->where(new RawSqlPredicateExpression($sql, $parameters));
        (new SqlQueryCompiler(new MySqlDialect()))->compile($query->expression());
    }

    public function testPostgresDollarQuotedLiteralsAreNotBoundAsParameters(): void
    {
        $query = Queryable::from(new InMemoryQueryProvider([]),'users')
            ->where(new RawSqlPredicateExpression('name <> $$? :ignored;$$ AND id = ?', [3]));
        $compiled = (new SqlQueryCompiler(new PostgreSqlDialect()))->compile($query->expression());
        self::assertSame(['p0'=>3], $compiled->parameters);
        self::assertStringContainsString('$$? :ignored;$$', $compiled->sql);
    }
}
