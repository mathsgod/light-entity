<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, Entity, ModelBuilder, Table};
use Light\Entity\Sql\{SqlQueryCompiler, SqliteDialect, MySqlDialect, PostgreSqlDialect, SqlServerDialect};
use Light\Linq\{Queryable, InMemoryQueryProvider};
use PDO;
use PHPUnit\Framework\TestCase;

#[Table('cs_users')]
final class ClosureUser extends Entity {}
#[Table('cs_roles')]
final class ClosureRole extends Entity {}
#[Table('cs_permissions')]
final class ClosurePermission extends Entity {}

final class ClosureContext extends DbContext
{
    protected function onModelCreating(ModelBuilder $builder): void
    {
        $builder->entity(ClosureRole::class)->hasQueryFilter('active', ['active'=>1]);
    }
}

final class ClosureSubqueryTest extends TestCase
{
    private function fixture(): ClosureContext
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE cs_users(id INTEGER PRIMARY KEY, status TEXT); CREATE TABLE cs_roles(id INTEGER PRIMARY KEY, user_id INT, name TEXT, active INT); CREATE TABLE cs_permissions(id INTEGER PRIMARY KEY, role_id INT, user_id INT)');
        $pdo->exec("INSERT INTO cs_users VALUES(1,'active'),(2,'active'),(3,'inactive'); INSERT INTO cs_roles VALUES(10,1,'admins',1),(11,1,'admins',1),(12,2,'admins',0),(13,3,'users',1); INSERT INTO cs_permissions VALUES(100,10,1)");
        return new ClosureContext($pdo);
    }

    public function testContainsClosureIsASingleSqlQueryAndAppliesBothPredicates(): void
    {
        $db = $this->fixture();
        $adminIds = $db->set(ClosureRole::class)->where(['name'=>'admins'])->select(fn($r) => $r->user_id);
        $query = $db->set(ClosureUser::class)->where(fn($u) => $adminIds->contains($u->id) && $u->status == 'active');
        self::assertSame([1], $query->select(fn($u) => $u->id)->toArray());
        $compiled = $query->asQueryable()->provider()->compile($query->asQueryable()->expression());
        self::assertStringContainsString(' IN (SELECT ', $compiled->sql);
        self::assertSame([1,'admins','active'], array_values($compiled->parameters));
    }

    public function testCorrelatedAnyAndNegationRespectFilters(): void
    {
        $db = $this->fixture();
        $roles = $db->set(ClosureRole::class)->asQueryable();
        $users = $db->set(ClosureUser::class);
        $query = $users->where(fn($u) => $roles->any(fn($r) => $r->user_id == $u->id && $r->name == 'admins'));
        self::assertSame([1], $query->select(fn($u) => $u->id)->toArray());
        self::assertSame([1,2], $query->ignoreQueryFilters()->select(fn($u) => $u->id)->toArray());
        $notAdmins = $users->where(fn($u) => !$roles->any(fn($r) => $r->user_id == $u->id && $r->name == 'admins'));
        self::assertSame([2,3], $notAdmins->select(fn($u) => $u->id)->toArray());
    }

    public function testNestedAnyResolvesBothOuterLevels(): void
    {
        $db = $this->fixture();
        $roles = $db->set(ClosureRole::class)->asQueryable();
        $permissions = $db->set(ClosurePermission::class)->asQueryable();
        $query = $db->set(ClosureUser::class)->where(fn($u) => $roles->any(
            fn($r) => $r->user_id == $u->id && $permissions->any(
                fn($p) => $p->role_id == $r->id && $p->user_id == $u->id
            )
        ));
        self::assertSame([1], $query->select(fn($u) => $u->id)->toArray());
    }

    public function testSameTableCorrelationAndEmptyAny(): void
    {
        $db = $this->fixture();
        $users = $db->set(ClosureUser::class);
        $others = $users->asQueryable();
        self::assertSame([1,2], $users->where(fn($u) => $others->any(fn($v) => $v->id > $u->id))
            ->select(fn($u) => $u->id)->toArray());
        $empty = $db->set(ClosureRole::class)->where(['name'=>'missing'])->asQueryable();
        self::assertSame(0, $users->where(fn($u) => $empty->any())->count());
        self::assertSame(3, $users->where(fn($u) => !$empty->any())->count());
    }

    public function testAnyWithPaginationAndProjectedOuterRows(): void
    {
        $db = $this->fixture();
        $roles = $db->set(ClosureRole::class)->orderBy(\Light\Linq\Expr::field('id'))->take(1)->asQueryable();
        $query = $db->set(ClosureUser::class)->select(fn($u) => ['key'=>$u->id])->distinct()
            ->where(fn($u) => $roles->any(fn($r) => $r->user_id == $u->key));
        // any(predicate) on a paged source must preserve LINQ operator ordering or fail explicitly.
        $this->expectException(\LogicException::class);
        $query->toArray();
    }

    public function testCorrelatedAnyWorksForBulkWrites(): void
    {
        $db = $this->fixture();
        $roles = $db->set(ClosureRole::class)->asQueryable();
        $users = $db->set(ClosureUser::class)->where(fn($u) => $roles->any(fn($r) => $r->user_id == $u->id && $r->name == 'admins'));
        self::assertSame(1, $users->executeUpdate(['status'=>'admin']));
        self::assertSame('admin', $users->single()->status);
        self::assertSame(1, $users->executeDelete());
    }

    public function testProjectedOuterRowsKeepCorrelationAfterDerivedTableWrapping(): void
    {
        $db = $this->fixture();
        $roles = $db->set(ClosureRole::class)->asQueryable();
        $query = $db->set(ClosureUser::class)->select(fn($u) => ['key'=>$u->id])->distinct()
            ->where(fn($u) => $roles->any(fn($r) => $r->user_id == $u->key && $r->name == 'admins'));
        self::assertSame([['key'=>1]], $query->toArray());
    }

    public function testRawSourcesAndNestedSubqueriesDoNotCollideParameters(): void
    {
        $db = $this->fixture();
        $roles = $db->set(ClosureRole::class)
            ->fromSqlRaw('SELECT * FROM cs_roles WHERE name = :p0', ['p0'=>'admins'])->asQueryable();
        $query = $db->set(ClosureUser::class)->where(fn($u) => $u->status == 'active' && $roles->any(fn($r) => $r->user_id == $u->id));
        self::assertSame([1], $query->select(fn($u) => $u->id)->toArray());
        $compiled = $query->asQueryable()->provider()->compile($query->asQueryable()->expression());
        self::assertSame(['active','admins',1], array_values($compiled->parameters));
    }

    public function testClosureConvertersApplyOnceToNewInnerPredicates(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE cs_users(id INTEGER PRIMARY KEY,status TEXT); CREATE TABLE cs_roles(id INTEGER PRIMARY KEY,user_id INT,name TEXT,active INT)');
        $pdo->exec("INSERT INTO cs_users VALUES(1,'x:active'); INSERT INTO cs_roles VALUES(10,1,'x:admins',1)");
        $converter = new \Light\Entity\ValueConverter(fn($v) => 'x:'.$v, fn($v) => substr($v,2));
        $usersMapping = new \Light\Entity\EntityMapping(ClosureUser::class, 'cs_users', ['id','status'], converters:['status'=>$converter]);
        $rolesMapping = new \Light\Entity\EntityMapping(ClosureRole::class, 'cs_roles', ['id','user_id','name','active'], converters:['name'=>$converter]);
        $db = new DbContext($pdo, mappings:[$usersMapping,$rolesMapping]);
        $roles = $db->set(ClosureRole::class);
        $query = $db->set(ClosureUser::class)->where(fn($u) => $u->status == 'active' && $roles->any(fn($r) => $r->user_id == $u->id && $r->name == 'admins'));
        self::assertSame([1], $query->select(fn($u) => $u->id)->toArray());
        // Existing predicates on a captured query already contain provider values.
        $filteredRoles = $db->set(ClosureRole::class)->where(['name'=>'admins']);
        self::assertSame(1, $db->set(ClosureUser::class)->where(fn($u) => $filteredRoles->any())->count());
    }

    public function testDifferentContextsAndUnsupportedClosuresAreRejectedBeforeSql(): void
    {
        $db = $this->fixture();
        $roles = $this->fixture()->set(ClosureRole::class)->asQueryable();
        $this->expectException(\Light\Linq\ClosureTranslationException::class);
        $db->set(ClosureUser::class)->where(fn($u) => $roles->any(fn($r) => $r->user_id == $u->id));
    }

    public function testCompilerCanBeReusedAfterCorrelatedQueries(): void
    {
        $provider = new InMemoryQueryProvider([]);
        $users = Queryable::from($provider, 'users');
        $others = Queryable::from($provider, 'users');
        $compiler = new SqlQueryCompiler(new SqliteDialect());
        $compiler->compile($users->where(fn($u) => $others->any(fn($v) => $v->id > $u->id))->expression());
        $plain = $compiler->compile($users->where(fn($u) => $u->id == 1)->expression());
        self::assertStringNotContainsString('__q', $plain->sql);
        self::assertSame(['p0'=>1], $plain->parameters);
    }

    public function testAllDialectsUseDistinctAliasesAndSharedParameters(): void
    {
        $provider = new InMemoryQueryProvider([]);
        $users = Queryable::from($provider,'users');
        $roles = Queryable::from($provider,'roles');
        $query = $users->where(fn($u) => $u->status == 'active' && $roles->any(fn($r) => $r->user_id == $u->id && $r->name == 'admins'));
        foreach ([new SqliteDialect(), new MySqlDialect(), new PostgreSqlDialect(), new SqlServerDialect()] as $dialect) {
            $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
            self::assertStringContainsString('EXISTS (SELECT ', $compiled->sql);
            self::assertStringContainsString('__q0', $compiled->sql);
            self::assertStringContainsString('__q1', $compiled->sql);
            self::assertSame(['p0'=>'active', 'p1'=>'admins'], $compiled->parameters);
        }
    }
}
