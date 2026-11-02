<?php

declare(strict_types=1);
namespace Light\Entity\Tests;

use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use Light\Linq\Expression\MethodCallExpression;
use Light\Entity\Sql\{SqlQueryCompiler, SqliteDialect, MySqlDialect, PostgreSqlDialect, SqlServerDialect};
use Light\Entity\SqlQueryProvider;
use PHPUnit\Framework\TestCase;

final class SetOperationsTest extends TestCase
{
    private function queries(): array
    {
        $p = new InMemoryQueryProvider([]);
        return [Queryable::from($p, 'a'), Queryable::from($p, 'b')];
    }

    public function testSetOperationsCompileForEveryDialect(): void
    {
        [$a, $b] = $this->queries();
        $a = $a->where(Expr::gt(Expr::field('id'), 0))->select(Expr::field('id'));
        $b = $b->where(Expr::lt(Expr::field('id'), 5))->select(Expr::field('id'));
        foreach ([new SqliteDialect(), new MySqlDialect(), new PostgreSqlDialect(), new SqlServerDialect()] as $d) {
            foreach (['concat'=>'UNION ALL', 'union'=>'UNION', 'intersect'=>'INTERSECT', 'except'=>'EXCEPT'] as $method=>$operator) {
                $c = (new SqlQueryCompiler($d))->compile($a->$method($b)->expression());
                if ($d instanceof MySqlDialect && in_array($method, ['intersect','except'], true)) {
                    self::assertStringContainsString($method === 'intersect' ? 'WHERE EXISTS' : 'WHERE NOT EXISTS', $c->sql);
                    self::assertStringContainsString(' IS NULL AND ', $c->sql);
                    self::assertStringContainsString('SELECT DISTINCT', $c->sql);
                } else self::assertStringContainsString(' '.$operator.' ', $c->sql);
                self::assertSame(['p0'=>0,'p1'=>5], $c->parameters);
                self::assertSame('scalar-list', $c->resultMode);
            }
        }
    }

    public function testNamedRowSetsRemainComposableAndPreservePaging(): void
    {
        [$a, $b] = $this->queries();
        $q = $a->orderBy(Expr::field('id'))->take(2)->select(Expr::fields('id'))
            ->union($b->select(Expr::fields('id')))->where(Expr::gt(Expr::field('id'), 1))
            ->orderByDescending(Expr::field('id'))->take(3);
        $compiler = new SqlQueryCompiler(new SqliteDialect());
        $c = $compiler->compile($q->expression());
        self::assertStringContainsString('FROM "a" ORDER BY "id" ASC LIMIT 2', $c->sql);
        self::assertStringEndsWith('WHERE ("id" > :p0) ORDER BY "id" DESC LIMIT 3', $c->sql);
        $sum = $compiler->compile(new MethodCallExpression($q->expression(), 'sum', ['selector'=>Expr::field('id')]));
        self::assertStringStartsWith('SELECT COALESCE(SUM("id"), 0)', $sum->sql);
        self::assertStringContainsString(' UNION ', $sum->sql);
    }

    public function testUnpagedSourceOrderingIsRemovedAndNestingIsPreserved(): void
    {
        [$a, $b] = $this->queries();
        $a = $a->orderBy(Expr::field('id'))->select(Expr::field('id'));
        $b = $b->select(Expr::field('id'));
        $c = (new SqlQueryCompiler(new SqlServerDialect()))->compile($a->union($b)->except($b)->expression());
        self::assertStringNotContainsString('ORDER BY', $c->sql);
        self::assertStringContainsString(' UNION ', $c->sql);
        self::assertStringContainsString(' EXCEPT ', $c->sql);
    }

    public function testUnprojectedSqlSourcesAreRejected(): void
    {
        [$a, $b] = $this->queries();
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($a->union($b)->expression());
    }

    public function testMismatchedColumnNamesAndOrderAreRejected(): void
    {
        [$a, $b] = $this->queries();
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($a->select(Expr::fields('id','name'))
            ->intersect($b->select(Expr::fields('name','id')))->expression());
    }

    public function testScalarAndSingleColumnRowCannotBeMixed(): void
    {
        [$a, $b] = $this->queries();
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($a->select(Expr::field('id'))
            ->concat($b->select(Expr::projection(['__linq_value'=>Expr::field('id')])))->expression());
    }

    public function testScalarSetFieldCompositionRequiresNamedProjections(): void
    {
        [$a, $b] = $this->queries();
        $this->expectException(\LogicException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($a->select(Expr::field('id'))
            ->union($b->select(Expr::field('id')))->orderBy(Expr::field('id'))->expression());
    }

    public function testDbSetsProjectMappedColumnsAndReturnUntrackedQueries(): void
    {
        [$a, $b] = $this->queries();
        $context = $this->createStub(\Light\Entity\DbContext::class);
        $mapping = new \Light\Entity\EntityMapping(SetOperationEntity::class, 'a', ['id']);
        $left = new \Light\Entity\DbSet($context, $mapping, $a);
        $right = new \Light\Entity\DbSet($context, $mapping, $b);
        foreach (['concat','union','intersect','except'] as $method) {
            $query = $left->$method($right);
            self::assertInstanceOf(Queryable::class, $query);
            $c = (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression());
            self::assertSame('rows', $c->resultMode);
        }
    }

    public function testSqliteExecution(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite required.');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE a(id INTEGER); CREATE TABLE b(id INTEGER); INSERT INTO a VALUES(1),(2),(2),(3),(NULL),(NULL); INSERT INTO b VALUES(2),(3),(4),(NULL)');
        $p = new SqlQueryProvider($pdo, new SqliteDialect());
        $a = Queryable::from($p, 'a')->select(Expr::field('id'));
        $b = Queryable::from($p, 'b')->select(Expr::field('id'));
        self::assertSame(10, $a->concat($b)->count());
        self::assertSame(5, $a->union($b)->count());
        self::assertSame(3, $a->intersect($b)->count());
        self::assertSame([1], $a->except($b)->toArray());
        self::assertSame([4], $b->except($a)->toArray());
        self::assertTrue($a->intersect($b)->contains(null));
    }
}

final class SetOperationEntity
{
    public int $id;
}
