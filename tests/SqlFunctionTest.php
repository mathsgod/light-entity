<?php

declare(strict_types=1);

namespace PhpEntity\Tests\Functions;

use PDO;
use PhpEntity\{DbContext, Entity, SqlFunctionRegistry, Table};
use PhpEntity\Sql\{FunctionExpression, MySqlDialect, PostgreSqlDialect, SqlDialect, SqliteDialect, SqlQueryCompiler, SqlServerDialect};
use PhpLinq\{Expr, InMemoryQueryProvider, Queryable};
use PhpLinq\Expression\MethodCallExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlFunctionTest extends TestCase
{
    public static function dialects(): iterable
    {
        yield 'mysql' => [new MySqlDialect(), 'scores'];
        yield 'sqlserver' => [new SqlServerDialect(), 'dbo'];
        yield 'postgresql' => [new PostgreSqlDialect(), 'public'];
        yield 'sqlite' => [new SqliteDialect(), null];
    }

    #[DataProvider('dialects')]
    public function testFunctionCompilesInWhereOrderingAndNamedProjection(SqlDialect $dialect, ?string $schema): void
    {
        $registry = new SqlFunctionRegistry();
        $registry->register('GetCustomScore', $schema, 1);
        $score = $registry->call('getcustomscore', Expr::field('user_id'));
        $query = Queryable::from(new InMemoryQueryProvider([]), 'users')
            ->where(Expr::gt($score, 80))->orderByDescending($score)
            ->select(Expr::projection(['id'=>'user_id', 'score'=>$score]));
        $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
        $function = $dialect->quoteIdentifier(($schema === null ? '' : $schema.'.').'GetCustomScore')
            .'('.$dialect->quoteIdentifier('user_id').')';
        self::assertSame(3, substr_count($compiled->sql, $function));
        self::assertStringContainsString(' WHERE ('.$function.' > :p0)', $compiled->sql);
        self::assertStringContainsString(' ORDER BY '.$function.' DESC', $compiled->sql);
        self::assertSame(['p0'=>80], $compiled->parameters);
        self::assertSame('rows', $compiled->resultMode);
    }

    public function testScalarProjectionModesAndRepeatedArgumentsUseDistinctParameters(): void
    {
        $registry = new SqlFunctionRegistry();
        $registry->register('EchoValue', parameterCount: 1);
        $payload = "x'); DROP TABLE users; --";
        $call = $registry->call('EchoValue', $payload);
        $query = Queryable::from(new InMemoryQueryProvider([]), 'users')
            ->where(Expr::eq($call, 'expected'))->orderBy($call)->select($call);
        $compiler = new SqlQueryCompiler(new SqliteDialect());
        $compiled = $compiler->compile($query->expression());
        self::assertSame('scalar-list', $compiled->resultMode);
        self::assertSame(['p0'=>$payload,'p1'=>$payload,'p2'=>'expected','p3'=>$payload], $compiled->parameters);
        self::assertStringNotContainsString($payload, $compiled->sql);
        $first = $compiler->compile(new MethodCallExpression($query->expression(), 'first'));
        self::assertSame('first-scalar', $first->resultMode);
        self::assertStringContainsString('LIMIT 1', $first->sql);
        $count = $compiler->compile(new MethodCallExpression($query->expression(), 'count'));
        self::assertSame('count', $count->resultMode);
    }

    public function testFunctionArgumentsInJoinKeysAreQualifiedRecursively(): void
    {
        $registry = new SqlFunctionRegistry();
        $registry->register('NormalizeId', parameterCount: 1);
        $key = $registry->call('NormalizeId', Expr::field('id'));
        $source = Queryable::from(new InMemoryQueryProvider([]), 'users');
        $query = $source->join($source, $key, $key, Expr::projection(['id'=>'outer.id']));
        $sql = (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression())->sql;
        self::assertStringContainsString('"NormalizeId"("outer"."id") = "NormalizeId"("inner"."id")', $sql);
    }

    public function testScalarFunctionProjectionCannotBeUsedAsRowJoinSource(): void
    {
        $registry = new SqlFunctionRegistry();
        $registry->register('Score');
        $source = Queryable::from(new InMemoryQueryProvider([]), 'users');
        $query = $source->select($registry->call('Score', Expr::field('id')))
            ->join($source, Expr::field('id'), Expr::field('id'), Expr::field('outer.id'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Join sources must return rows');
        (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression());
    }

    public function testDirectCallsAreAllowedAndDoNotImplicitlyRegisterMappings(): void
    {
        $registry = new SqlFunctionRegistry();
        $direct = $registry->call('Score', 1, 2);
        self::assertSame('Score', $direct->definition->name);
        self::assertNull($direct->definition->schema);
        self::assertNull($direct->definition->parameterCount);
        self::assertCount(2, $direct->arguments);
        $registry->register('Score', schema: 'dbo', parameterCount: 1);
        $mapped = $registry->call('score', 1);
        self::assertSame('dbo', $mapped->definition->schema);
        self::assertSame(1, $mapped->definition->parameterCount);
        self::assertNull($direct->definition->schema); // Existing expressions stay immutable.
        $this->expectException(\InvalidArgumentException::class);
        $registry->call('Score', 1, 2);
    }

    public function testDirectCallStillValidatesIdentifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SqlFunctionRegistry())->call('Score(id); DROP TABLE users', 1);
    }

    public function testParameterCountIsCheckedAtExpressionConstruction(): void
    {
        $registry = new SqlFunctionRegistry();
        $registry->register('Score', parameterCount: 1);
        self::assertInstanceOf(FunctionExpression::class, $registry->call('Score', 1));
        $this->expectException(\InvalidArgumentException::class);
        $registry->call('Score');
    }

    public function testDuplicateRegistrationIsCaseInsensitive(): void
    {
        $registry = new SqlFunctionRegistry();
        $registry->register('Score');
        $this->expectException(\LogicException::class);
        $registry->register('score');
    }

    public static function invalidMappings(): iterable
    {
        yield 'empty name' => ['', null, null];
        yield 'SQL expression' => ['Score(id)', null, null];
        yield 'qualified name' => ['dbo.Score', null, null];
        yield 'invalid schema' => ['Score', 'dbo; DROP TABLE users', null];
        yield 'empty schema' => ['Score', '', null];
        yield 'negative count' => ['Score', null, -1];
    }

    #[DataProvider('invalidMappings')]
    public function testInvalidMappingsAreRejected(string $name, ?string $schema, ?int $count): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SqlFunctionRegistry())->register($name, $schema, $count);
    }

    public function testSqliteRejectsSchemaQualifiedFunctions(): void
    {
        $registry = new SqlFunctionRegistry();
        $registry->register('Score', 'main');
        $query = Queryable::from(new InMemoryQueryProvider([]), 'users')->select($registry->call('Score'));
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression());
    }

    public function testSqlFunctionIsNeverEvaluatedByInMemoryProvider(): void
    {
        $registry = new SqlFunctionRegistry();
        $registry->register('Score');
        $query = Queryable::from(new InMemoryQueryProvider(['users'=>[['id'=>1]]]), 'users')
            ->select($registry->call('Score', Expr::field('id')));
        $this->expectException(\LogicException::class);
        $query->toArray();
    }
}
