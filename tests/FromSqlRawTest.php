<?php

declare(strict_types=1);

namespace Light\Entity\Tests\RawSql;

use PDO;
use Light\Entity\{DbContext, Entity, EntityState, QueryTrackingBehavior, Table};
use Light\Linq\Expr;
use PHPUnit\Framework\TestCase;

#[Table('blogs')]
final class RawBlog extends Entity {}
#[Table('owners')]
final class RawOwner extends Entity {}

final class FromSqlRawTest extends TestCase
{
    private PDO $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE owners(id INTEGER PRIMARY KEY, name TEXT); CREATE TABLE blogs(id INTEGER PRIMARY KEY, owner_id INTEGER REFERENCES owners(id), name TEXT, active BOOLEAN); CREATE TABLE notes(id INTEGER PRIMARY KEY, blog_id INTEGER REFERENCES blogs(id), body TEXT); INSERT INTO owners VALUES(1,'Mary'),(2,'John'); INSERT INTO blogs VALUES(1,1,'Zoo',1),(2,1,'Apple',1),(3,1,'Banana',0),(4,2,'Other',1); INSERT INTO notes VALUES(10,2,'note')");
        $this->db = new DbContext($this->pdo);
    }

    public function testRawSourceSupportsFilterOrderingPaginationAndAggregates(): void
    {
        $blogs = $this->db->set(RawBlog::class)->fromSqlRaw('SELECT * FROM blogs WHERE owner_id = ?', [1]);
        self::assertSame(3, $blogs->count());
        self::assertTrue($blogs->any(Expr::eq(Expr::field('active'), true)));
        $rows = $blogs->where(Expr::eq(Expr::field('active'), true))->orderBy(Expr::field('name'))->skip(1)->take(1)->toArray();
        self::assertCount(1, $rows);
        self::assertSame('Zoo', $rows[0]->name);
        self::assertTrue($rows[0]->active);
        self::assertSame(['Apple','Banana','Zoo'], $blogs->orderBy(Expr::field('name'))->select(Expr::field('name'))->toArray());
    }

    public function testNamedRepeatedParametersQuotesAndInjectionLikeValuesAreBound(): void
    {
        $root = $this->db->set(RawBlog::class);
        $blogs = $root->fromSqlRaw('SELECT * FROM blogs WHERE owner_id = :id OR id = :id -- ? :fake ;', [':id'=>1]);
        self::assertSame(3, $blogs->count());
        self::assertSame('Apple', $blogs->orderBy(Expr::field('name'))->first()->name);
        $payload = "x'; DELETE FROM blogs; --";
        self::assertSame([], $root->fromSqlRaw('SELECT * FROM blogs WHERE name = ?', [$payload])->toArray());
        self::assertSame(4, $root->count());
        $projection = $root->fromSqlRaw("SELECT id, 'it''s ? :fake ;' AS label FROM blogs WHERE id = :id", ['id'=>1])
            ->select(Expr::projection(['id'=>'id','label'=>'label']))->first();
        self::assertSame(['id'=>1,'label'=>"it's ? :fake ;"], $projection);
    }

    public function testRawEntitiesShareTrackingAndUpdateTheirMappedTable(): void
    {
        $root = $this->db->set(RawBlog::class);
        $tracked = $root->find(2);
        $tracked->name = 'Pending';
        $raw = $root->fromSqlRaw('SELECT * FROM blogs WHERE id = ?', [2])->first();
        self::assertSame($tracked, $raw);
        self::assertSame('Pending', $raw->name);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('Pending', $this->pdo->query('SELECT name FROM blogs WHERE id = 2')->fetchColumn());
        self::assertSame(EntityState::Unchanged, $this->db->entry($raw)->state);
    }

    public function testNoTrackingAndContextDefaultArePreserved(): void
    {
        $root = $this->db->set(RawBlog::class);
        $tracked = $root->find(1);
        $read = $root->asNoTracking()->fromSqlRaw('SELECT * FROM blogs WHERE id = ?', [1])->first();
        self::assertNotSame($tracked, $read);
        self::assertSame(EntityState::Detached, $this->db->entry($read)->state);
        $read->name = 'Ignored';
        self::assertSame(0, $this->db->saveChanges());
        $this->db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::NoTracking;
        $query = $root->fromSqlRaw('SELECT * FROM blogs WHERE id = ?', [2]);
        self::assertSame(EntityState::Detached, $this->db->entry($query->first())->state);
        self::assertSame(EntityState::Unchanged, $this->db->entry($query->asTracking()->first())->state);
    }

    public function testIncludesLoadRelationsAndSupportDynamicUpdates(): void
    {
        $this->db->set(RawOwner::class);
        $blog = $this->db->set(RawBlog::class)->include('Notes')->fromSqlRaw('SELECT * FROM blogs WHERE id = ?', [2])->include('Owner')->first();
        self::assertSame('Mary', $blog->getOwner()->name);
        $note = $blog->getNotes()->first();
        self::assertSame('note', $note->body);
        $note->body = 'Changed';
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('Changed', $this->pdo->query('SELECT body FROM notes')->fetchColumn());
        $read = $this->db->set(RawBlog::class)->fromSqlRaw('SELECT * FROM blogs WHERE id = ?', [2])->asNoTracking()->include('Notes')->first();
        self::assertSame(EntityState::Detached, $this->db->entry($read->getNotes()->first())->state);
    }

    public function testRawSourceRequiresRootAndDoesNotMutateOriginalQuery(): void
    {
        $root = $this->db->set(RawBlog::class);
        self::assertSame(1, $root->fromSqlRaw('SELECT * FROM blogs WHERE id = 1')->count());
        self::assertSame(4, $root->count());
        $this->expectException(\LogicException::class);
        $root->where(Expr::eq(Expr::field('id'), 1))->fromSqlRaw('SELECT * FROM blogs');
    }

    public function testFindCannotBypassRawSourceFilterUsingTrackedIdentity(): void
    {
        $root = $this->db->set(RawBlog::class);
        $root->find(1);
        $this->expectException(\LogicException::class);
        $root->fromSqlRaw('SELECT * FROM blogs WHERE id = 2')->find(1);
    }

    public function testMissingColumnsThrowEvenWhenEntityIsAlreadyTracked(): void
    {
        $root = $this->db->set(RawBlog::class);
        $root->find(1);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('missing mapped column');
        $root->fromSqlRaw('SELECT id FROM blogs WHERE id = 1')->first();
    }

    public function testMissingColumnsAlsoThrowForNoTracking(): void
    {
        $this->expectException(\LogicException::class);
        $this->db->set(RawBlog::class)->fromSqlRaw('SELECT id FROM blogs')->asNoTracking()->toArray();
    }

    public function testScalarProjectionCanSelectSubsetAndEmptyQueriesBehaveNormally(): void
    {
        $root = $this->db->set(RawBlog::class);
        self::assertSame(['Zoo'], $root->fromSqlRaw('SELECT name FROM blogs WHERE id = 1')->select(Expr::field('name'))->toArray());
        self::assertSame([], $root->fromSqlRaw('SELECT * FROM blogs WHERE id = ?', [99])->toArray());
        self::assertSame([], $this->db->changeTracker->entries());
        $this->expectException(\UnderflowException::class);
        $root->fromSqlRaw('SELECT * FROM blogs WHERE id = 99')->first();
    }

    public function testRawAndOrdinarySourcesCanJoinAndCompose(): void
    {
        $blogs = $this->db->set(RawBlog::class)->fromSqlRaw('SELECT * FROM blogs WHERE owner_id = :id', ['id'=>1]);
        $owners = $this->db->set(RawOwner::class)->fromSqlRaw('SELECT * FROM owners WHERE id = :id', ['id'=>1]);
        $rows = $blogs->join($owners, Expr::field('owner_id'), Expr::field('id'), Expr::projection(['name'=>'outer.name','owner'=>'inner.name']))
            ->where(Expr::eq(Expr::field('name'), 'Apple'))->toArray();
        self::assertSame([['name'=>'Apple','owner'=>'Mary']], $rows);
        self::assertSame([], $this->db->changeTracker->entries());
    }

    public function testCompoundSelectAndComputedAliasesCanBeUsedAsSource(): void
    {
        $rows = $this->db->set(RawBlog::class)->fromSqlRaw('SELECT name AS title FROM blogs WHERE id = ? UNION ALL SELECT name AS title FROM blogs WHERE id = ?', [1,2])
            ->orderBy(Expr::field('title'))->select(Expr::field('title'))->toArray();
        self::assertSame(['Apple','Zoo'], $rows);
    }
}
