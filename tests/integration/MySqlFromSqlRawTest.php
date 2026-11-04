<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Integration;

use PDO;
use Light\Entity\{DbContext, Entity, EntityState, Table};
use Light\Entity\Tests\Support\MySqlDatabase;
use Light\Linq\Expr;
use PHPUnit\Framework\TestCase;

#[Table('blogs')]
final class MySqlRawBlog extends Entity {}
#[Table('owners')]
final class MySqlRawOwner extends Entity {}

final class MySqlFromSqlRawTest extends TestCase
{
    use MySqlDatabase;

    private function rawDatabase(): PDO
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE owners(id INT PRIMARY KEY, name VARCHAR(60))');
        $pdo->exec('CREATE TABLE blogs(id INT PRIMARY KEY, owner_id INT, name VARCHAR(60), active BOOLEAN, FOREIGN KEY(owner_id) REFERENCES owners(id))');
        $pdo->exec('CREATE TABLE notes(id INT PRIMARY KEY, blog_id INT, body VARCHAR(60), FOREIGN KEY(blog_id) REFERENCES blogs(id))');
        $pdo->exec("INSERT INTO owners VALUES(1,'Mary'),(2,'John')");
        $pdo->exec("INSERT INTO blogs VALUES(1,1,'Zoo',1),(2,1,'Apple',1),(3,1,'Banana',0),(4,2,'Other',1)");
        $pdo->exec("INSERT INTO notes VALUES(10,2,'note')");
        return $pdo;
    }

    public function testRawFilterOrderingPaginationAndNativeRepeatedNamedParameters(): void
    {
        $pdo = $this->rawDatabase();
        $db = new DbContext($pdo);
        $root = $db->set(MySqlRawBlog::class);
        $blogs = $root->fromSqlRaw('SELECT * FROM blogs WHERE owner_id = :p0 OR id = :p0', ['p0'=>1]);
        self::assertSame(3, $blogs->count());
        self::assertTrue($blogs->any(Expr::eq(Expr::field('active'), true)));
        $page = $blogs->where(Expr::eq(Expr::field('active'), true))->orderBy(Expr::field('name'))->skip(1)->take(1)->toArray();
        self::assertCount(1, $page);
        self::assertSame('Zoo', $page[0]->name);
        self::assertSame(1, $page[0]->active); // MySQL BOOLEAN is reported as TINYINT(1).
        self::assertSame(['Apple','Banana','Zoo'], $blogs->orderBy(Expr::field('name'))->select(Expr::field('name'))->toArray());
        $payload = "x'; DELETE FROM blogs; --";
        self::assertSame([], $root->fromSqlRaw('SELECT * FROM blogs WHERE name = ?', [$payload])->toArray());
        self::assertSame(4, $root->count());
        $projection = $root->fromSqlRaw('SELECT * FROM blogs WHERE owner_id = :p0', ['p0'=>1])
            ->where(Expr::eq(Expr::field('id'), 2))->select(Expr::projection(['tag'=>Expr::value('outer'),'name'=>'name']))->first();
        self::assertSame(['tag'=>'outer','name'=>'Apple'], $projection);
    }

    public function testRawSqlTracksMappedEntitiesIncludesAndUpdatesWhileNoTrackingStaysDetached(): void
    {
        $pdo = $this->rawDatabase();
        $db = new DbContext($pdo);
        $db->set(MySqlRawOwner::class);
        $root = $db->set(MySqlRawBlog::class);
        $tracked = $root->find(2);
        $tracked->name = 'Pending';
        $blog = $root->fromSqlRaw('SELECT * FROM blogs WHERE id = ?', [2])->include('Owner')->include('Notes')->first();
        self::assertSame($tracked, $blog);
        self::assertSame('Mary', $blog->getOwner()->name);
        $note = $blog->getNotes()->first();
        $note->body = 'Updated';
        self::assertSame(2, $db->saveChanges());
        self::assertSame('Pending', $pdo->query('SELECT name FROM blogs WHERE id = 2')->fetchColumn());
        self::assertSame('Updated', $pdo->query('SELECT body FROM notes')->fetchColumn());
        $read = $root->asNoTracking()->fromSqlRaw('SELECT * FROM blogs WHERE id = ?', [2])->include('Notes')->first();
        self::assertNotSame($tracked, $read);
        self::assertSame(EntityState::Detached, $db->entry($read)->state);
        self::assertSame(EntityState::Detached, $db->entry($read->getNotes()->first())->state);
        $read->name = 'Ignored';
        self::assertSame(0, $db->saveChanges());
    }

    public function testRawSourcesJoinAndCommentsArePreserved(): void
    {
        $pdo = $this->rawDatabase();
        $db = new DbContext($pdo);
        $blogs = $db->set(MySqlRawBlog::class)->fromSqlRaw('SELECT * FROM blogs WHERE owner_id = ? -- :fake ? ;', [1]);
        $owners = $db->set(MySqlRawOwner::class)->fromSqlRaw('SELECT * FROM owners WHERE id = ?', [1]);
        $rows = $blogs->join($owners, Expr::field('owner_id'), Expr::field('id'), Expr::projection(['name'=>'outer.name','owner'=>'inner.name']))
            ->where(Expr::eq(Expr::field('name'), 'Apple'))->toArray();
        self::assertSame([['name'=>'Apple','owner'=>'Mary']], $rows);
        self::assertSame([], $db->changeTracker->entries());
    }

    public function testMissingColumnsAreRejectedForTrackedEntities(): void
    {
        $pdo = $this->rawDatabase();
        $db = new DbContext($pdo);
        $root = $db->set(MySqlRawBlog::class);
        $root->find(1);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('missing mapped column');
        $root->fromSqlRaw('SELECT id FROM blogs WHERE id = 1')->first();
    }
}
