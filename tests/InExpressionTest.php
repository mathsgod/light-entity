<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, Entity, ModelBuilder, Table};
use Light\Entity\Sql\{InExpression, NotExpression, SqlQueryCompiler, SqliteDialect, MySqlDialect, PostgreSqlDialect, SqlServerDialect};
use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use PDO;
use PHPUnit\Framework\TestCase;

#[Table('in_users')]
final class InUser extends Entity {}
#[Table('in_roles')]
final class InRole extends Entity {}

final class InContext extends DbContext
{
    protected function onModelCreating(ModelBuilder $builder): void
    {
        $builder->entity(InRole::class)->hasQueryFilter('active', ['active'=>1]);
    }
}

final class InExpressionTest extends TestCase
{
    private function fixture(): DbContext
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE in_users(id INT PRIMARY KEY, name TEXT); CREATE TABLE in_roles(id INTEGER PRIMARY KEY, user_id INT, name TEXT, active INT)');
        $pdo->exec("INSERT INTO in_users VALUES(1,'Ada'),(2,'Bob'),(3,'Cara'); INSERT INTO in_roles(user_id,name,active) VALUES(1,'admins',1),(1,'admins',1),(2,'admins',0),(3,'users',1)");
        return new InContext($pdo);
    }

    public function testSubqueryExecutesInSqlWithFiltersAndComposesWithOuterConditions(): void
    {
        $db = $this->fixture();
        $ids = $db->set(InRole::class)->where(['name'=>'admins'])->select(Expr::field('user_id'));
        $root = $db->set(InUser::class);
        self::assertSame('Ada', $root->whereIn('id', $ids)->where(['name'=>'Ada'])->single()->name);
        self::assertSame(2, $root->whereNotIn('id', $ids)->count());
        self::assertSame(3, $root->count());
        self::assertSame(2, $root->whereIn('id', $ids)->ignoreQueryFilters()->count());
        $unfiltered = $db->set(InRole::class)->ignoreQueryFilters()->where(['name'=>'admins'])->select(Expr::field('user_id'));
        self::assertSame(2, $root->whereIn('id', $unfiltered)->count());
        $predicate = new NotExpression(new InExpression(Expr::field('id'), $ids));
        self::assertSame(2, $root->where(Expr::both($predicate, Expr::gt(Expr::field('id'), 0)))->count());
    }

    public function testNestedSubqueriesAndBulkWrites(): void
    {
        $db = $this->fixture();
        $users = $db->set(InUser::class);
        $inner = $users->where(['id'=>1])->select(Expr::field('id'));
        $roles = $db->set(InRole::class)->whereIn('user_id', $inner)->select(Expr::field('user_id'));
        self::assertSame(1, $users->whereIn('id', $roles)->executeUpdate(['name'=>'Admin']));
        self::assertSame('Admin', $users->whereIn('id', $roles)->single()->name);
        self::assertSame(1, $users->whereIn('id', $roles)->executeDelete());
        self::assertSame(2, $users->count());
    }

    public function testNullAndEmptySubqueryUseSqlMembershipSemantics(): void
    {
        $db = $this->fixture();
        $users = $db->set(InUser::class);
        $empty = $db->set(InRole::class)->where(['name'=>'missing'])->select(Expr::field('user_id'));
        self::assertSame(0, $users->whereIn('id', $empty)->count());
        self::assertSame(3, $users->whereNotIn('id', $empty)->count());
        $null = $db->set(InRole::class)->fromSqlRaw('SELECT NULL AS user_id, 1 AS active')->select(Expr::field('user_id'));
        self::assertSame(0, $users->whereIn('id', $null)->count());
        self::assertSame(0, $users->whereNotIn('id', $null)->count());
    }

    public function testDialectsShareParameterBindingsAndRemoveUnneededOrdering(): void
    {
        $provider = new InMemoryQueryProvider([]);
        $inner = Queryable::from($provider, 'roles')->where(Expr::eq(Expr::field('name'), "admins' OR 1=1 --"))
            ->orderBy(Expr::field('user_id'))->select(Expr::field('user_id'));
        $outer = Queryable::from($provider, 'users')->where(Expr::gt(Expr::field('id'), 0))
            ->where(new InExpression(Expr::field('id'), $inner))->where(Expr::eq(Expr::field('name'), 'Ada'));
        foreach ([new SqliteDialect(), new MySqlDialect(), new PostgreSqlDialect(), new SqlServerDialect()] as $dialect) {
            $compiled = (new SqlQueryCompiler($dialect))->compile($outer->expression());
            self::assertStringContainsString(' IN (SELECT ', $compiled->sql);
            self::assertStringNotContainsString('ORDER BY', $compiled->sql);
            self::assertStringNotContainsString("admins'", $compiled->sql);
            self::assertSame(['p0'=>0, 'p1'=>"admins' OR 1=1 --", 'p2'=>'Ada'], $compiled->parameters);
        }
    }

    public function testRejectsMultipleColumnSubquery(): void
    {
        $db = $this->fixture();
        $subquery = $db->set(InRole::class)->select(Expr::fields('user_id', 'name'));
        $this->expectException(\LogicException::class);
        $db->set(InUser::class)->whereIn('id', $subquery)->count();
    }

    public function testRejectsUnprojectedSubquery(): void
    {
        $db = $this->fixture();
        $this->expectException(\LogicException::class);
        $db->set(InUser::class)->whereIn('id', $db->set(InRole::class)->asQueryable())->count();
    }

    public function testRejectsDifferentProviders(): void
    {
        $db = $this->fixture();
        $other = $this->fixture();
        $this->expectException(\InvalidArgumentException::class);
        $db->set(InUser::class)->whereIn('id', $other->set(InRole::class)->select(Expr::field('user_id')));
    }

    public function testSubqueryRequiresOneSelectedColumnWithoutADatabase(): void
    {
        $source = Queryable::from(new InMemoryQueryProvider([]), 'roles');
        foreach ([$source, $source->select(Expr::fields('user_id', 'name'))] as $invalid) {
            $query = Queryable::from($source->provider(), 'users')->where(new InExpression(Expr::field('id'), $invalid));
            try {
                (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression());
                self::fail('Expected a single-column validation error.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('single-column', $error->getMessage());
            }
        }
        $valid = $source->select(Expr::fields('user_id'));
        $query = Queryable::from($source->provider(), 'users')->where(new InExpression(Expr::field('id'), $valid));
        self::assertStringContainsString(' IN (SELECT ', (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression())->sql);
    }

    public function testNestedRawSubqueriesAndPaginationKeepBindings(): void
    {
        $provider = new InMemoryQueryProvider([]);
        $raw = new Queryable($provider, new \Light\Entity\Sql\RawSqlSourceExpression('SELECT user_id FROM roles WHERE name = :p0', ['p0'=>'admins']));
        $inner = $raw->select(Expr::field('user_id'))->orderBy(Expr::field('user_id'))->take(1);
        $middle = Queryable::from($provider, 'users')->where(new InExpression(Expr::field('id'), $inner))->select(Expr::field('id'));
        $outer = Queryable::from($provider, 'users')->where(Expr::gt(Expr::field('id'), 0))->where(new InExpression(Expr::field('id'), $middle, true));
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile($outer->expression());
        self::assertSame(['p0'=>0, 'p1'=>'admins'], $compiled->parameters);
        self::assertStringContainsString(' NOT IN (SELECT ', $compiled->sql);
        self::assertStringContainsString('ORDER BY "user_id" ASC LIMIT 1', $compiled->sql);
    }

    public function testSourceRewritingReachesSubqueriesInsideLogicalPredicates(): void
    {
        $provider = new InMemoryQueryProvider([]);
        $mapping = new \Light\Entity\EntityMapping(InRole::class, 'in_roles', ['user_id', 'active'], 'user_id');
        $source = new \Light\Entity\Sql\EntityQuerySourceExpression($mapping, new \Light\Linq\Expressions\SourceExpression('in_roles'));
        $inner = (new Queryable($provider, $source))->select(Expr::field('user_id'));
        $outer = Queryable::from($provider, 'in_users')->where(Expr::both(
            Expr::gt(Expr::field('id'), 0), new NotExpression(new InExpression(Expr::field('id'), $inner)),
        ));
        $expand = static function ($entitySource) use ($provider) {
            $query = new Queryable($provider, $entitySource->source);
            if ($entitySource->ignoredFilters !== null && !in_array('active', $entitySource->ignoredFilters, true)) {
                $query = $query->where(Expr::eq(Expr::field('active'), 1));
            }
            return $query->expression();
        };
        $compiler = new SqlQueryCompiler(new SqliteDialect());
        $filtered = $compiler->compile(\Light\Entity\QueryFilterRewriter::rewrite($outer->expression(), $expand));
        self::assertStringContainsString('"active" = :p1', $filtered->sql);
        self::assertSame(['p0'=>0, 'p1'=>1], $filtered->parameters);
        $ignored = \Light\Entity\QueryFilterRewriter::ignore($outer->expression(), ['active']);
        $unfiltered = $compiler->compile(\Light\Entity\QueryFilterRewriter::rewrite($ignored, $expand));
        self::assertStringNotContainsString('"active"', $unfiltered->sql);
        self::assertSame(['p0'=>0], $unfiltered->parameters);
    }
}
