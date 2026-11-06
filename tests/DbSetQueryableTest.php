<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, DbSet, Entity, EntityState, ModelBuilder, Table};
use Light\Linq\{Expr, IQueryable, Queryable};
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

#[Table('iq_users')]
final class QueryableUser extends Entity {}
#[Table('iq_roles')]
final class QueryableRole extends Entity {}
#[Table('iq_members')]
final class QueryableMember extends Entity {}

final class QueryablePDO extends PDO
{
    public array $reads = [];
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_starts_with($query, 'SELECT')) { $this->reads[] = $query; }
        return parent::prepare($query, $options);
    }
}

final class QueryableContext extends DbContext
{
    protected function onModelCreating(ModelBuilder $builder): void
    {
        $builder->entity(QueryableRole::class)->hasQueryFilter('active', ['active'=>1]);
    }
}

final class DbSetQueryableTest extends TestCase
{
    private function fixture(): array
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $pdo = new QueryablePDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE iq_users(id INTEGER PRIMARY KEY,status TEXT,age INT); CREATE TABLE iq_roles(id INTEGER PRIMARY KEY,user_id INT REFERENCES iq_users(id),name TEXT,active INT); CREATE TABLE iq_members(a INT,b INT,PRIMARY KEY(a,b))');
        $pdo->exec("INSERT INTO iq_users VALUES(1,'active',30),(2,'active',20),(3,'inactive',10); INSERT INTO iq_roles VALUES(10,1,'admins',1),(11,2,'admins',0),(12,3,'users',1); INSERT INTO iq_members VALUES(1,2),(1,3)");
        $db = new QueryableContext($pdo);
        $users = $db->set(QueryableUser::class);
        $roles = $db->set(QueryableRole::class);
        return [$pdo,$db,$users,$roles];
    }

    public function testDbSetIsAQueryableAndDirectCaptureIsLazy(): void
    {
        [$pdo,$db,$users,$roles] = $this->fixture();
        self::assertInstanceOf(IQueryable::class, $users);
        self::assertSame($users->asQueryable()->expression(), $users->expression());
        self::assertSame($users->asQueryable()->provider(), $users->provider());
        $reads = count($pdo->reads);
        $query = $users->where(fn($u) => $roles->any(fn($r) => $r->user_id == $u->id && $r->name == 'admins'));
        self::assertSame($reads, count($pdo->reads));
        self::assertSame([1], $query->select(fn($u) => $u->id)->toArray());
        self::assertSame($reads+1, count($pdo->reads));
        self::assertSame([], $db->changeTracker->entries());
        self::assertSame([1,2], $query->ignoreQueryFilters()->select(fn($u) => $u->id)->toArray());
    }

    public function testInterfacePreservesMaterializationTrackingAndInclude(): void
    {
        [, $db,$users,$roles] = $this->fixture();
        $db->relationship(QueryableUser::class, 'Roles', 'iq_roles', ['user_id'], QueryableRole::class);
        $query = $users->include('Roles')->where(fn($u) => $roles->any(fn($r) => $r->user_id == $u->id && $r->name == 'admins'));
        $read = static fn(IQueryable $source) => $source->first();
        $entity = $read($query);
        self::assertInstanceOf(QueryableUser::class, $entity);
        self::assertSame($entity, $users->find(1));
        self::assertSame(EntityState::Unchanged, $db->entry($entity)->state);
        self::assertSame([10], array_map(fn($r) => $r->id, $entity->getRoles()->toArray()));
        $detached = $read($query->asNoTracking());
        self::assertNotSame($entity, $detached);
        self::assertSame(EntityState::Detached, $db->entry($detached)->state);
    }

    public function testDirectAnyAcceptsClosuresAndConvertsMappedPredicates(): void
    {
        [$pdo,$db,$users,$roles] = $this->fixture();
        $any = static fn(IQueryable $source, \Closure $predicate) => $source->any($predicate);
        self::assertTrue($any($roles, fn($r) => $r->name == 'admins'));
        self::assertFalse($any($roles, fn($r) => $r->user_id == 2));
        self::assertTrue($roles->ignoreQueryFilters()->any(fn($r) => $r->user_id == 2));
        $pdo->exec("UPDATE iq_users SET status='inactive' WHERE id=2");
        self::assertTrue($users->any(fn($u) => $roles->any(fn($r) => $r->user_id == $u->id && $r->name == 'admins')));
    }

    public function testDefaultsMatchTheMixedInterfaceContract(): void
    {
        [,,$users] = $this->fixture();
        $empty = $users->where(['id'=>99]);
        $check = static function (IQueryable $query): void {
            self::assertSame('none', $query->firstOrDefault(default:'none'));
            self::assertSame(123, $query->singleOrDefault(default:123));
            self::assertFalse($query->orderBy(Expr::field('id'))->lastOrDefault(default:false));
        };
        $check($empty);
        self::assertInstanceOf(QueryableUser::class, $users->firstOrDefault(default:'none'));
    }

    public function testEntityContainsUsesAllKeysAndRespectsQueryShape(): void
    {
        [, $db,$users] = $this->fixture();
        $entity = new QueryableUser();
        $entity->id = 2;
        self::assertTrue($users->contains($entity));
        self::assertFalse($users->where(['status'=>'inactive'])->contains($entity));
        self::assertFalse($users->orderBy(Expr::field('id'))->take(1)->contains($entity));
        self::assertFalse($users->contains(2));
        self::assertFalse($users->contains(new QueryableUser()));
        self::assertSame([], $db->changeTracker->entries());
        $member = new QueryableMember();
        $member->a = 1;
        $member->b = 2;
        self::assertTrue($db->set(QueryableMember::class)->contains($member));
        $member->b = 4;
        self::assertFalse($db->set(QueryableMember::class)->contains($member));
    }

    public function testAggregatesAndEnumerablePreserveEntitySemantics(): void
    {
        [, $db,$users] = $this->fixture();
        self::assertSame(60, $users->sum(Expr::field('age')));
        self::assertEquals(20, $users->average(Expr::field('age')));
        self::assertSame(10, $users->min(Expr::field('age')));
        self::assertSame(30, $users->max(Expr::field('age')));
        self::assertSame([], $db->changeTracker->entries());
        $entities = $users->asEnumerable()->where(fn($u) => $u->age >= 20)->toArray();
        self::assertCount(2, $entities);
        self::assertSame($entities[0], $users->find(1));
    }

    public function testMixedQueryableCompositionChecksProviders(): void
    {
        [,,$users,$roles] = $this->fixture();
        $inner = $roles->select(Expr::fields('id','user_id'));
        $joined = $users->join($inner, Expr::field('id'), Expr::field('user_id'), Expr::projection(['id'=>'outer.id','role'=>'inner.id']));
        self::assertSame([['id'=>1,'role'=>10],['id'=>3,'role'=>12]], $joined->orderBy(Expr::field('id'))->toArray());
        $projected = $users->where(['id'=>1])->select(Expr::fields('id','status','age'));
        self::assertSame(4, $users->concat($projected)->count());
        self::assertSame(3, $users->union($projected)->count());
        [, ,$other] = $this->fixture();
        $this->expectException(\InvalidArgumentException::class);
        $users->union($other);
    }

    public function testUnsupportedSqlOperatorsStillFailExplicitly(): void
    {
        [,, $users,$roles] = $this->fixture();
        $queries = [
            $users->groupJoin($roles, Expr::field('id'), Expr::field('user_id'), Expr::fields('outer.id')),
            $users->zip($roles, Expr::fields('outer.id')),
            $users->chunk(2),
        ];
        foreach ($queries as $query) {
            try { $query->toArray(); self::fail('Expected SQL provider rejection.'); }
            catch (\LogicException $error) { self::assertStringContainsString('asEnumerable()', $error->getMessage()); }
        }
    }

    public function testPreloadedCollectionsKeepCachedEntitiesForLocalOperations(): void
    {
        [$pdo,$db,$users] = $this->fixture();
        $db->relationship(QueryableUser::class, 'Roles', 'iq_roles', ['user_id'], QueryableRole::class);
        $user = $users->include('Roles')->where(['id'=>1])->first();
        $roles = $user->getRoles();
        self::assertInstanceOf(IQueryable::class, $roles);
        $role = $roles->first();
        $pdo->exec('DELETE FROM iq_roles WHERE id=10');
        $reads = count($pdo->reads);
        self::assertTrue($roles->contains($role));
        self::assertSame(10, $roles->sum(Expr::field('id')));
        self::assertEquals(10, $roles->average(Expr::field('id')));
        self::assertSame(10, $roles->min(Expr::field('id')));
        self::assertSame(10, $roles->max(Expr::field('id')));
        self::assertSame($role, $roles->asEnumerable()->first());
        self::assertSame($reads, count($pdo->reads));
    }

    public function testDirectDbSetCaptureStillRejectsAnotherContext(): void
    {
        [,, $users] = $this->fixture();
        [,,, $roles] = $this->fixture();
        $this->expectException(\Light\Linq\ClosureTranslationException::class);
        $users->where(fn($u) => $roles->any(fn($r) => $r->user_id == $u->id));
    }
}
