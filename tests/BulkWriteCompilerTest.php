<?php
declare(strict_types=1);
namespace Light\Entity\Tests;

use Light\Entity\Sql\{MySqlDialect, PostgreSqlDialect, SqlDialect, SqliteDialect, SqlServerDialect, SqlQueryCompiler};
use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BulkWriteCompilerTest extends TestCase
{
    public static function dialects(): iterable
    {
        yield [new MySqlDialect(), '`'];
        yield [new PostgreSqlDialect(), '"'];
        yield [new SqliteDialect(), '"'];
        yield [new SqlServerDialect(), '['];
    }

    #[DataProvider('dialects')]
    public function testCompilesParameterizedWritesAndResetsParameterState(SqlDialect $dialect, string $quote): void
    {
        $compiler = new SqlQueryCompiler($dialect);
        $query = Queryable::from(new InMemoryQueryProvider([]), 'app.orders')
            ->where(Expr::eq(Expr::field('status'), 'pending'))
            ->where(Expr::eq(Expr::field('previous_status'), null));
        $normalize = static fn ($sql) => str_replace(['`', '"', '[', ']'], '', $sql);
        $update = $compiler->compileModification($query->expression(), ['status'=>'cancelled', 'total'=>12]);
        self::assertSame('UPDATE app.orders SET status = :p0, total = :p1 WHERE (status = :p2) AND (previous_status IS NULL)', $normalize($update->sql));
        self::assertStringContainsString($quote.'orders', $update->sql);
        self::assertSame(['p0'=>'cancelled', 'p1'=>12, 'p2'=>'pending'], $update->parameters);
        self::assertSame('affected', $update->resultMode);
        $delete = $compiler->compileModification($query->expression());
        self::assertSame('DELETE FROM app.orders WHERE (status = :p0) AND (previous_status IS NULL)', $normalize($delete->sql));
        self::assertSame(['p0'=>'pending'], $delete->parameters);
    }
}
