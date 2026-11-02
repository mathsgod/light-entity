<?php

declare(strict_types=1);

namespace PhpEntity\Tests\RawSql;

use PhpEntity\Sql\{MySqlDialect, PostgreSqlDialect, RawSqlSourceExpression, SqlDialect, SqliteDialect, SqlQueryCompiler, SqlServerDialect};
use PhpLinq\{Expr, InMemoryQueryProvider, Queryable};
use PhpLinq\Expression\MethodCallExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RawSqlCompilerTest extends TestCase
{
    public static function dialects(): iterable
    {
        yield 'mysql' => [new MySqlDialect()];
        yield 'sqlite' => [new SqliteDialect()];
        yield 'postgresql' => [new PostgreSqlDialect()];
        yield 'sqlserver' => [new SqlServerDialect()];
    }

    #[DataProvider('dialects')]
    public function testRawSourceIsWrappedAndParametersCannotCollide(SqlDialect $dialect): void
    {
        $source = new RawSqlSourceExpression('SELECT * FROM blogs WHERE owner_id = :p0', ['p0'=>7]);
        $query = new Queryable(new InMemoryQueryProvider([]), $source);
        $query = $query->where(Expr::eq(Expr::field('active'), true))->orderBy(Expr::field('name'))->skip(2)->take(3)
            ->select(Expr::projection(['id'=>'id', 'tag'=>Expr::value('outer')]));
        $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
        self::assertStringContainsString("FROM (SELECT * FROM blogs WHERE owner_id = :p1\n) AS ".$dialect->quoteIdentifier('__raw_source'), $compiled->sql);
        self::assertSame(['p0'=>'outer','p1'=>7,'p2'=>true], $compiled->parameters);
        self::assertStringContainsString(' ORDER BY '.$dialect->quoteIdentifier('name').' ASC', $compiled->sql);
    }

    public function testPositionalAndRepeatedNamedParametersAreRenumbered(): void
    {
        $compiler = new SqlQueryCompiler(new SqliteDialect());
        $positional = $compiler->compile(new RawSqlSourceExpression('SELECT * FROM blogs WHERE id > ? AND owner_id = ?', [1,2]));
        self::assertSame(['p0'=>1,'p1'=>2], $positional->parameters);
        $named = $compiler->compile(new RawSqlSourceExpression('SELECT * FROM blogs WHERE id = :id OR owner_id = :id', [':id'=>3]));
        self::assertStringContainsString('id = :p0 OR owner_id = :p1', $named->sql);
        self::assertSame(['p0'=>3,'p1'=>3], $named->parameters);
    }

    public function testQuotesAndCommentsDoNotBecomeParametersOrStatements(): void
    {
        $sql = "/* ? :id ; */ SELECT 'it''s ? :id ;' AS label, :id AS id -- :other ? ;";
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile(new RawSqlSourceExpression($sql, ['id'=>5]));
        self::assertSame(['p0'=>5], $compiled->parameters);
        self::assertStringContainsString("'it''s ? :id ;'", $compiled->sql);
        self::assertStringContainsString("-- :other ? ;\n)", $compiled->sql);
    }

    public function testPostgresCastsDollarQuotesAndNestedCommentsArePreserved(): void
    {
        $sql = 'SELECT $$?; :fake$$ AS text, $tag$:fake;?$tag$ AS other, :id::integer AS id /* outer /* ? */ :fake */';
        $compiled = (new SqlQueryCompiler(new PostgreSqlDialect()))->compile(new RawSqlSourceExpression($sql, ['id'=>5]));
        self::assertSame(['p0'=>5], $compiled->parameters);
        self::assertStringContainsString(':p0::integer', $compiled->sql);
        self::assertStringContainsString('$$?; :fake$$', $compiled->sql);
    }

    public function testDialectQuotedIdentifiersAndMysqlArithmeticAreLexed(): void
    {
        $mysql = (new SqlQueryCompiler(new MySqlDialect()))->compile(new RawSqlSourceExpression('SELECT `weird``?;:id`, ?--? AS score # ? :fake', [3,1]));
        self::assertSame(['p0'=>3,'p1'=>1], $mysql->parameters);
        self::assertStringContainsString(':p0--:p1', $mysql->sql);
        $server = (new SqlQueryCompiler(new SqlServerDialect()))->compile(new RawSqlSourceExpression('SELECT [weird]]?;:id], :id AS id', ['id'=>1]));
        self::assertSame(['p0'=>1], $server->parameters);
    }

    public function testRawSourcesCanJoinWithoutParameterNameCollisions(): void
    {
        $provider = new InMemoryQueryProvider([]);
        $outer = new Queryable($provider, new RawSqlSourceExpression('SELECT * FROM blogs WHERE owner_id = :id', ['id'=>1]));
        $inner = new Queryable($provider, new RawSqlSourceExpression('SELECT * FROM owners WHERE id > :id', ['id'=>2]));
        $query = $outer->leftJoin($inner, Expr::field('owner_id'), Expr::field('id'), Expr::projection(['name'=>'outer.name','owner'=>'inner.name']));
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression());
        self::assertSame(['p0'=>1,'p1'=>2], $compiled->parameters);
        self::assertStringContainsString(' LEFT JOIN ', $compiled->sql);
        self::assertStringContainsString('owner_id = :p0', $compiled->sql);
        self::assertStringContainsString('id > :p1', $compiled->sql);
    }

    public function testRawSourceAggregatesAndScalarFirstUseNormalModes(): void
    {
        $query = new Queryable(new InMemoryQueryProvider([]), new RawSqlSourceExpression('SELECT * FROM blogs WHERE owner_id = ?', [2]));
        $compiler = new SqlQueryCompiler(new SqliteDialect());
        $count = $compiler->compile(new MethodCallExpression($query->expression(), 'count'));
        self::assertSame('count', $count->resultMode);
        self::assertSame(['p0'=>2], $count->parameters);
        $first = $compiler->compile(new MethodCallExpression($query->select(Expr::field('name'))->expression(), 'first'));
        self::assertSame('first-scalar', $first->resultMode);
        self::assertStringContainsString('LIMIT 1', $first->sql);
    }

    public static function invalidSources(): iterable
    {
        yield 'non select' => ['DELETE FROM blogs', []];
        yield 'procedure' => ['EXEC GetBlogs', []];
        yield 'cte' => ['WITH b AS (SELECT 1) SELECT * FROM b', []];
        yield 'trailing separator' => ['SELECT * FROM blogs;', []];
        yield 'multiple statements' => ['SELECT * FROM blogs; DELETE FROM blogs', []];
        yield 'select into' => ['SELECT id INTO copy FROM blogs', []];
        yield 'missing positional' => ['SELECT ? AS id', []];
        yield 'extra positional' => ['SELECT 1 AS id', [2]];
        yield 'missing named' => ['SELECT :id AS id', ['other'=>1]];
        yield 'extra named' => ['SELECT :id AS id', ['id'=>1,'other'=>2]];
        yield 'mixed markers' => ['SELECT :id + ? AS id', ['id'=>1]];
        yield 'duplicate names' => ['SELECT :id AS id', ['id'=>1,':id'=>2]];
        yield 'invalid keys' => ['SELECT :id AS id', ['bad-key'=>1]];
        yield 'unterminated quote' => ["SELECT 'missing", []];
        yield 'unterminated comment' => ['SELECT 1 /*', []];
        yield 'only comment' => ['/* SELECT 1 */', []];
        yield 'open parenthesis' => ['SELECT (1', []];
        yield 'closing parenthesis' => ['SELECT 1)', []];
    }

    #[DataProvider('invalidSources')]
    public function testUnsupportedSourcesAreRejected(string $sql, array $parameters): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile(new RawSqlSourceExpression($sql, $parameters));
    }

    public function testMysqlExecutableCommentsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SqlQueryCompiler(new MySqlDialect()))->compile(new RawSqlSourceExpression('SELECT 1 /*! + ? */', [2]));
    }
}
