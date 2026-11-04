<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, Entity, ModelBuilder, EntityState};
use Light\Linq\Expr;
use PDO;
use PHPUnit\Framework\TestCase;

#[\Light\Entity\Table('gf_users')]
class FilterUser extends Entity {}
#[\Light\Entity\Table('gf_posts')]
class FilterPost extends Entity {}
#[\Light\Entity\Table('gf_users')]
class UnfilteredUser extends Entity {}

class FilterContext extends DbContext
{
    public int $tenantId;
    public int $builds = 0;
    public ?ModelBuilder $builder = null;
    public function __construct(PDO $pdo, int $tenantId)
    {
        parent::__construct($pdo);
        $this->tenantId = $tenantId; // Must exist before onModelCreating runs.
    }
    protected function onModelCreating(ModelBuilder $builder): void
    {
        ++$this->builds;
        $this->builder = $builder;
        foreach ([FilterUser::class, FilterPost::class] as $class) {
            $builder->entity($class)
                ->hasQueryFilter('softDelete', Expr::eq(Expr::field('is_deleted'), 0))
                ->hasQueryFilter('tenant', fn (self $context) => ['tenant_id'=>$context->tenantId]);
        }
    }
}

class GlobalQueryFilterTest extends TestCase
{
    protected function connection(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        return new PDO('sqlite::memory:');
    }
    private function fixture(int $tenant = 1): array
    {
        $pdo = $this->connection();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE gf_users(id INT PRIMARY KEY, tenant_id INT, name VARCHAR(60), code VARCHAR(60) UNIQUE, is_deleted INT)');
        $pdo->exec('CREATE TABLE gf_posts(id INT PRIMARY KEY,user_id INT,tenant_id INT,title VARCHAR(60),owner_code VARCHAR(60),is_deleted INT,FOREIGN KEY(user_id) REFERENCES gf_users(id),FOREIGN KEY(owner_code) REFERENCES gf_users(code))');
        $pdo->exec("INSERT INTO gf_users VALUES(1,1,'Alice','A',0),(2,1,'Bob','B',1),(3,2,'Cara','C',0),(4,1,'Dave','D',0)");
        $pdo->exec("INSERT INTO gf_posts VALUES(10,1,1,'Live','A',0),(11,1,1,'Deleted','A',1),(12,3,2,'Other','C',0),(13,4,1,'Another','D',0),(14,1,2,'Wrong tenant','A',0)");
        $db = new FilterContext($pdo, $tenant);
        $db->relationship(FilterUser::class, 'Posts', 'gf_posts', ['user_id'], FilterPost::class);
        $db->relationship(FilterUser::class, 'CodePosts', 'gf_posts', ['owner_code'], FilterPost::class);
        return [$pdo,$db];
    }
    private function ids(iterable $rows): array
    {
        $ids=[];
        foreach ($rows as $row) { $ids[]=$row->id; }
        sort($ids);
        return $ids;
    }

    public function testModelIsBuiltLazilyOnceAndPredicatesReadExecutingContext(): void
    {
        [$pdo,$db]=$this->fixture();
        self::assertSame(0,$db->builds);
        $set=$db->set(FilterUser::class)->asNoTracking();
        self::assertSame(1,$db->builds);
        self::assertSame([1,4],$this->ids($set->toArray()));
        $db->tenantId=2;
        self::assertSame([3],$this->ids($set->toArray()));
        self::assertSame(1,$db->builds);
        $other=new FilterContext($pdo,1);
        self::assertSame([1,4],$this->ids($other->set(FilterUser::class)->asNoTracking()->toArray()));
        self::assertSame([1,2,3,4],$this->ids($db->set(UnfilteredUser::class)->toArray()));
    }

    public function testNamedIgnoreAllIgnoreAndCompositionAreQueryLocal(): void
    {
        [$pdo,$db]=$this->fixture();
        $root=$db->set(FilterUser::class)->asNoTracking();
        self::assertSame([1,2,4],$this->ids($root->ignoreQueryFilters(['softDelete'])->toArray()));
        self::assertSame([1,3,4],$this->ids($root->ignoreQueryFilters(['tenant'])->toArray()));
        self::assertSame([1,2,3,4],$this->ids($root->ignoreQueryFilters()->toArray()));
        self::assertSame([1,2,3,4],$this->ids($root->ignoreQueryFilters(['tenant'])->ignoreQueryFilters(['softDelete'])->toArray()));
        self::assertSame([1,4],$this->ids($root->ignoreQueryFilters([])->toArray()));
        self::assertSame([1,4],$this->ids($root->toArray()));
        self::assertSame([4],$this->ids($root->orderBy(Expr::field('id'))->skip(1)->take(1)->toArray()));
        self::assertSame([2],$this->ids($root->where(['id'=>2])->ignoreQueryFilters(['softDelete'])->toArray()));
    }

    public function testTerminalsProjectionAndAggregatesUseFilters(): void
    {
        [$pdo,$db]=$this->fixture();
        $set=$db->set(FilterUser::class)->asNoTracking();
        self::assertSame(2,$set->count());
        self::assertFalse($set->any(Expr::eq(Expr::field('id'),2)));
        self::assertTrue($set->all(Expr::eq(Expr::field('tenant_id'),1)));
        self::assertSame(1,$set->orderBy(Expr::field('id'))->first()->id);
        self::assertNull($set->firstOrDefault(Expr::eq(Expr::field('id'),2)));
        self::assertSame(4,$set->single(Expr::eq(Expr::field('id'),4))->id);
        self::assertEquals(5,$set->asQueryable()->sum(Expr::field('id')));
        self::assertSame([1,4],$set->orderBy(Expr::field('id'))->select(Expr::field('id'))->toArray());
    }

    public function testFindCannotBypassActiveFiltersUsingTrackedEntities(): void
    {
        [$pdo,$db]=$this->fixture();
        $set=$db->set(FilterUser::class);
        $deleted=$set->ignoreQueryFilters()->find(2);
        self::assertSame(EntityState::Unchanged,$db->entry($deleted)->state);
        self::assertNull($set->find(2));
        self::assertSame($deleted,$set->ignoreQueryFilters()->find(2));
        self::assertNotNull($set->find(1));
        $db->tenantId=2;
        self::assertNull($set->find(1));
        self::assertSame(3,$set->find(3)->id);
    }

    public function testRawSqlAndStreamingKeepFiltersAndParameters(): void
    {
        [$pdo,$db]=$this->fixture();
        $set=$db->set(FilterUser::class)->asNoTracking();
        self::assertSame([1,4],$this->ids(iterator_to_array($set,false)));
        self::assertSame([4],$this->ids($set->fromSqlRaw('SELECT * FROM gf_users WHERE id > ?', [1])->toArray()));
        self::assertSame([2,4],$this->ids($set->ignoreQueryFilters(['softDelete'])->fromSqlRaw('SELECT * FROM gf_users WHERE id > ?', [1])->toArray()));
        $query=$set->asQueryable();
        $compiled=$query->provider()->compile($query->expression());
        self::assertStringContainsString('WHERE',$compiled->sql);
        self::assertContains(1,$compiled->parameters);
        self::assertContains(0,$compiled->parameters);
    }

    public function testJoinsFilterBothSidesWithoutTurningLeftJoinIntoInnerJoin(): void
    {
        [$pdo,$db]=$this->fixture();
        $users=$db->set(FilterUser::class)->ignoreQueryFilters(['softDelete']);
        $posts=$db->set(FilterPost::class);
        $selector=Expr::projection(['uid'=>'outer.id','pid'=>'inner.id']);
        $rows=$users->leftJoin($posts,Expr::field('id'),Expr::field('user_id'),$selector)->orderBy(Expr::field('uid'))->toArray();
        self::assertSame([['uid'=>1,'pid'=>10],['uid'=>2,'pid'=>null],['uid'=>4,'pid'=>13]],$rows);
        self::assertCount(2,$users->join($posts,Expr::field('id'),Expr::field('user_id'),$selector)->toArray());
    }

    public function testSetOperationsFilterEachOperand(): void
    {
        [$pdo,$db]=$this->fixture();
        $root=$db->set(FilterUser::class);
        self::assertSame(3,$root->union($root->ignoreQueryFilters(['softDelete']))->count());
        self::assertSame(2,$root->intersect($root->ignoreQueryFilters())->count());
    }

    public function testBulkUpdatesAndDeletesUseFiltersButTrackedSavesRemainExplicit(): void
    {
        [$pdo,$db]=$this->fixture();
        $set=$db->set(FilterUser::class);
        self::assertSame(2,$set->executeUpdate(['name'=>'Updated']));
        self::assertSame('Bob',$pdo->query('SELECT name FROM gf_users WHERE id=2')->fetchColumn());
        self::assertSame('Cara',$pdo->query('SELECT name FROM gf_users WHERE id=3')->fetchColumn());
        $hidden=$set->ignoreQueryFilters(['softDelete'])->find(2);
        $hidden->name='Explicit';
        self::assertSame(1,$db->saveChanges());
        self::assertSame('Explicit',$pdo->query('SELECT name FROM gf_users WHERE id=2')->fetchColumn());
        // Delete a leaf table to preserve foreign keys.
        self::assertSame(2,$db->set(FilterPost::class)->executeDelete());
        self::assertSame(3,(int)$pdo->query('SELECT COUNT(*) FROM gf_posts')->fetchColumn());
    }

    public function testEagerAndLazyRelationsFilterTargetsAndHonorIgnoredNames(): void
    {
        [$pdo,$db]=$this->fixture();
        $root=$db->set(FilterUser::class)->asNoTracking()->where(['id'=>1]);
        $normal=$root->include('Posts')->include('CodePosts')->single();
        self::assertSame([10],$this->ids($normal->getPosts()->toArray()));
        self::assertSame([10],$this->ids($normal->getCodePosts()->toArray()));
        $ignored=$root->ignoreQueryFilters(['softDelete'])->include('Posts')->include('CodePosts')->single();
        self::assertSame([10,11],$this->ids($ignored->getPosts()->toArray()));
        self::assertSame([10,11],$this->ids($ignored->getCodePosts()->toArray()));
        $lazy=$root->ignoreQueryFilters()->single();
        self::assertSame([10,11,14],$this->ids($lazy->getPosts()->toArray()));
        self::assertSame([10],$this->ids($root->single()->getPosts()->toArray()));
    }

    public function testSameNameOverwritesAndBuilderCannotMutateAfterInitialization(): void
    {
        [$pdo,$unused]=$this->fixture();
        $db=new class($pdo) extends DbContext {
            public ?ModelBuilder $builder=null;
            protected function onModelCreating(ModelBuilder $builder): void {
                $this->builder=$builder;
                $builder->entity(FilterUser::class)->hasQueryFilter('one',['id'=>1])->hasQueryFilter('one',['id'=>4]);
            }
        };
        self::assertSame([4],$this->ids($db->set(FilterUser::class)->toArray()));
        $this->expectException(\LogicException::class);
        $db->builder->entity(FilterUser::class)->hasQueryFilter('late',['id'=>1]);
    }

    public function testFailedModelBuildCanRetryWithoutPartiallyRegisteredFilters(): void
    {
        [$pdo,$unused]=$this->fixture();
        $db=new class($pdo) extends DbContext {
            public bool $fail=true;
            protected function onModelCreating(ModelBuilder $builder): void {
                if ($this->fail) { $builder->entity(FilterUser::class)->hasQueryFilter('partial',['id'=>999]); throw new \RuntimeException('configuration failed'); }
                $builder->entity(FilterUser::class)->hasQueryFilter('live',['id'=>1]);
            }
        };
        try { $db->set(FilterUser::class); self::fail('Expected configuration failure.'); }
        catch (\RuntimeException $e) { self::assertSame('configuration failed',$e->getMessage()); }
        $db->fail=false;
        self::assertSame([1],$this->ids($db->set(FilterUser::class)->toArray()));
    }
    public function testIgnoringFiltersOnUnfilteredRootStillAppliesToRelatedEntities(): void
    {
        [$pdo,$db]=$this->fixture();
        $db->relationship(UnfilteredUser::class,'Posts','gf_posts',['user_id'],FilterPost::class);
        $root=$db->set(UnfilteredUser::class)->asNoTracking()->where(['id'=>1]);
        self::assertSame([10],$this->ids($root->include('Posts')->single()->getPosts()->toArray()));
        self::assertSame([10,11,14],$this->ids($root->ignoreQueryFilters()->include('Posts')->single()->getPosts()->toArray()));
    }

    public function testFilterConstantsUseConfiguredProviderConversion(): void
    {
        [$pdo,$unused]=$this->fixture();
        $mapping=\Light\Entity\EntityMapping::fromSchema(FilterUser::class,'gf_users',
            (new \Light\Entity\Schema\SchemaReader($pdo))->columns('gf_users'),
            ['name'=>new \Light\Entity\ValueConverter(strrev(...),strrev(...))]);
        $db=new class($pdo,mappings:[$mapping]) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void {
                $builder->entity(FilterUser::class)->hasQueryFilter('name',Expr::eq(Expr::field('name'),'ecilA'));
            }
        };
        $set=$db->set(FilterUser::class)->asNoTracking();
        self::assertSame(1,$set->single()->id);
        self::assertSame('ecilA',$set->single()->name);
        $query=$set->asQueryable();
        self::assertContains('Alice',$query->provider()->compile($query->expression())->parameters);
    }

    public function testInvalidConfigurationAndPredicateResultsAreRejected(): void
    {
        $builder=new ModelBuilder();
        foreach ([fn () => $builder->entity('MissingQueryFilterEntity'), fn () => $builder->entity(FilterUser::class)->hasQueryFilter(' ',[]) ] as $configure) {
            try { $configure(); self::fail('Expected invalid configuration.'); }
            catch (\InvalidArgumentException $e) { self::assertNotSame('',$e->getMessage()); }
        }
        [$pdo,$unused]=$this->fixture();
        $db=new class($pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void { $builder->entity(FilterUser::class)->hasQueryFilter('bad',fn () => 123); }
        };
        try { $db->set(FilterUser::class)->ignoreQueryFilters(['']); self::fail('Expected invalid name.'); }
        catch (\InvalidArgumentException $e) { self::assertNotSame('',$e->getMessage()); }
        $this->expectException(\LogicException::class);
        $db->set(FilterUser::class)->count();
    }

}
