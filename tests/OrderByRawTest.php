<?php
declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, EntityMapping, DbSet};
use Light\Entity\Sql\{MySqlDialect, PostgreSqlDialect, SqlDialect, SqliteDialect, SqlQueryCompiler, SqlServerDialect};
use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderByRawTest extends TestCase
{
    public static function dialects(): iterable
    {
        yield [new MySqlDialect()];
        yield [new SqliteDialect()];
        yield [new PostgreSqlDialect()];
        yield [new SqlServerDialect()];
    }

    private function root(): DbSet
    {
        return new DbSet(
            (new \ReflectionClass(DbContext::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(EntityMapping::class))->newInstanceWithoutConstructor(),
            Queryable::from(new InMemoryQueryProvider([]), 'IPGroup'),
        );
    }

    #[DataProvider('dialects')]
    public function testCorrelatedSubqueryAndParametersCompose(SqlDialect $dialect): void
    {
        $root = $this->root();
        $query = $root->whereRaw('datacenter_id > ?', [0])->orderByRaw(
            '(SELECT name FROM DataCenter WHERE DataCenter.datacenter_id = IPGroup.datacenter_id AND name <> :p0)',
            parameters: ['p0' => 'ignored'],
            descending: true,
        )->thenByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END -- trailing comment', [3])
            ->thenBy(Expr::field('id'));
        $compiler = new SqlQueryCompiler($dialect);
        $compiled = $compiler->compile($query->expression());
        self::assertSame(['p0' => 0, 'p1' => 'ignored', 'p2' => 3], $compiled->parameters);
        self::assertStringContainsString('DataCenter.datacenter_id = IPGroup.datacenter_id', $compiled->sql);
        self::assertStringContainsString("name <> :p1)\n) DESC", $compiled->sql);
        self::assertStringContainsString("ELSE 1 END -- trailing comment\n) ASC", $compiled->sql);
        self::assertStringNotContainsString('ORDER BY', $compiler->compile($root->expression())->sql);
    }

    public function testNewPrimaryOrderingReplacesPreviousOrdering(): void
    {
        $query = $this->root()->orderByRaw('old_column')->orderByRaw('new_column');
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression());
        self::assertStringNotContainsString('old_column', $compiled->sql);
        self::assertStringContainsString("(new_column\n) ASC", $compiled->sql);
    }

    public static function invalidExpressions(): iterable
    {
        yield ['', []];
        yield ["\0", []];
        yield ['-- comment only', []];
        yield ['id; DELETE FROM IPGroup', []];
        yield ['(id', []];
        yield ['id)', []];
        yield ['id + ?', []];
        yield ['id', [1]];
        yield ['id + :id', ['id' => 1, ':id' => 2]];
        yield ['id + ?', [[1]]];
    }

    #[DataProvider('invalidExpressions')]
    public function testInvalidExpressionsFailBeforeExecution(string $sql, array $parameters): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $query = $this->root()->orderByRaw($sql, $parameters);
        (new SqlQueryCompiler(new MySqlDialect()))->compile($query->expression());
    }

    public function testQuotedMarkersArePreservedAndRepeatedNamesAreBoundSeparately(): void
    {
        $query = $this->root()->orderByRaw("CASE WHEN name = :name OR name = :name THEN '?' ELSE ':ignored' END", ['name' => 'Ada']);
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression());
        self::assertSame(['p0' => 'Ada', 'p1' => 'Ada'], $compiled->parameters);
        self::assertStringContainsString("THEN '?' ELSE ':ignored' END", $compiled->sql);
    }
}
