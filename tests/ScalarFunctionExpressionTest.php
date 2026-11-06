<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, Entity, Table};
use Light\Entity\Sql\{SqlDialect, SqlQueryCompiler, MySqlDialect, PostgreSqlDialect, SqlServerDialect, SqliteDialect};
use Light\Linq\{Expr, Queryable, InMemoryQueryProvider};
use Light\Linq\Expressions\{FieldExpression, MethodCallExpression, ProjectionExpression, ScalarFunctionExpression};
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[Table('scalar_rows')]
final class ScalarRow extends Entity {}

final class ScalarFunctionExpressionTest extends TestCase
{
    public static function dialects(): iterable
    {
        yield 'mysql' => [new MySqlDialect()];
        yield 'postgresql' => [new PostgreSqlDialect()];
        yield 'sqlserver' => [new SqlServerDialect()];
        yield 'sqlite' => [new SqliteDialect()];
    }

    private function rows(): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider([]), 'scalar_rows');
    }

    #[DataProvider('dialects')]
    public function testNestedFunctionProjectionAndBoundValues(SqlDialect $dialect): void
    {
        $query = $this->rows()->where(fn($r) => strtolower($r->name) == 'alice')
            ->select(fn($r) => ['upper' => strtoupper($r->name), 'rounded' => round(abs($r->amount), 2)]);
        $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
        self::assertStringContainsString('LOWER('.$dialect->quoteIdentifier('name').') = :p1', $compiled->sql);
        self::assertStringContainsString('UPPER('.$dialect->quoteIdentifier('name').')', $compiled->sql);
        self::assertStringContainsString($dialect instanceof PostgreSqlDialect ? 'ROUND(CAST(ABS(' : 'ROUND(ABS(', $compiled->sql);
        self::assertSame(['p0' => 2, 'p1' => 'alice'], $compiled->parameters);
        self::assertSame('rows', $compiled->resultMode);
    }

    #[DataProvider('dialects')]
    public function testLengthCountsBytesRatherThanCharactersOrExcludingSpaces(SqlDialect $dialect): void
    {
        $query = $this->rows()->select(fn($r) => strlen($r->name));
        $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
        $name = $dialect->quoteIdentifier('name');
        $expected = match (true) {
            $dialect instanceof SqliteDialect => 'LENGTH(CAST('.$name.' AS BLOB))',
            $dialect instanceof SqlServerDialect => 'DATALENGTH('.$name.')',
            $dialect instanceof PostgreSqlDialect => 'OCTET_LENGTH('.$name.')',
            default => 'LENGTH('.$name.')',
        };
        self::assertStringContainsString($expected, $compiled->sql);
        self::assertSame('scalar-list', $compiled->resultMode);
        self::assertSame('first-scalar', (new SqlQueryCompiler($dialect))->compile(new MethodCallExpression($query->expression(), 'first'))->resultMode);
    }

    #[DataProvider('dialects')]
    public function testTrimHasExplicitWhitespaceSet(SqlDialect $dialect): void
    {
        $compiled = (new SqlQueryCompiler($dialect))->compile($this->rows()->select(fn($r) => trim($r->name))->expression());
        self::assertStringContainsString(match (true) {
            $dialect instanceof MySqlDialect => 'REGEXP_REPLACE(',
            $dialect instanceof PostgreSqlDialect => 'BTRIM(',
            default => 'TRIM(',
        }, $compiled->sql);
        self::assertCount(1, $compiled->parameters);
        self::assertStringContainsString("\t", $compiled->parameters['p0']);
        self::assertStringContainsString("\x0B", $compiled->parameters['p0']);
    }

    public function testSqliteNegativePrecisionScalesInsteadOfUsingNativeNegativePrecision(): void
    {
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile($this->rows()->select(fn($r) => round($r->amount, -1))->expression());
        self::assertStringContainsString('ROUND("amount" / CAST(:p0 AS REAL), 0) * CAST(:p1 AS REAL)', $compiled->sql);
        self::assertSame(['p0' => 10.0, 'p1' => 10.0], $compiled->parameters);
    }

    #[DataProvider('dialects')]
    public function testRoundWithoutPrecisionSuppliesZero(SqlDialect $dialect): void
    {
        $compiled = (new SqlQueryCompiler($dialect))->compile($this->rows()->select(fn($r) => round($r->amount))->expression());
        self::assertSame(['p0' => 0], $compiled->parameters);
    }

    #[DataProvider('dialects')]
    public function testGroupedFunctionArgumentsResolveAndValidate(SqlDialect $dialect): void
    {
        $query = $this->rows()->groupBy(Expr::field('name'))->select(new ProjectionExpression([
            'name' => new ScalarFunctionExpression('strtolower', [Expr::key()]),
        ]));
        self::assertStringContainsString('LOWER(', (new SqlQueryCompiler($dialect))->compile($query->expression())->sql);
        $this->expectException(\LogicException::class);
        $invalid = $this->rows()->groupBy(Expr::field('name'))->select(new ScalarFunctionExpression('abs', [Expr::field('amount')]));
        (new SqlQueryCompiler($dialect))->compile($invalid->expression());
    }

    #[DataProvider('dialects')]
    public function testFunctionJoinKeysQualifyTheirArguments(SqlDialect $dialect): void
    {
        $rows = $this->rows();
        $key = new ScalarFunctionExpression('strtolower', [new FieldExpression('name')]);
        $query = $rows->join($rows, $key, $key, Expr::fields('outer.id'));
        $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
        self::assertStringContainsString('LOWER('.$dialect->quoteIdentifier('outer.name').')', $compiled->sql);
        self::assertStringContainsString('LOWER('.$dialect->quoteIdentifier('inner.name').')', $compiled->sql);
    }

    public function testEntityFunctionsExecuteInSqlite(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE scalar_rows (id INTEGER PRIMARY KEY, name TEXT, amount REAL)');
        $insert = $pdo->prepare('INSERT INTO scalar_rows VALUES (?, ?, ?)');
        foreach ([[1, "\t Alice \r\n", -12.345], [2, 'é中😀 ', 125.0], [3, null, null], [4, '', -2.5]] as $row) { $insert->execute($row); }
        $rows = (new DbContext($pdo))->set(ScalarRow::class);
        self::assertSame(['alice', 'é中😀', null, ''], $rows->select(fn($r) => strtolower(trim($r->name)))->toArray());
        self::assertSame([10, 10, null, 0], $rows->select(fn($r) => strlen($r->name))->toArray());
        self::assertEquals([12.35, 125.0, null, 2.5], $rows->select(fn($r) => round(abs($r->amount), 2))->toArray());
        self::assertEquals([-10.0, 130.0, null, 0.0], $rows->select(fn($r) => round($r->amount, -1))->toArray());
    }
}
