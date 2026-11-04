<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, Entity, EntityState, Table};
use Light\Linq\Expr;
use PDO;
use PHPUnit\Framework\TestCase;

#[Table('raw_users')]
final class WhereRawUser extends Entity {}

class WhereRawTest extends TestCase
{
    protected function connection(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        return new PDO('sqlite::memory:');
    }

    protected function fixture(): DbContext
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE raw_teams(id INT PRIMARY KEY, role VARCHAR(60)); CREATE TABLE raw_users(id INT PRIMARY KEY, team_id INT, name VARCHAR(60), age INT)');
        $pdo->exec("INSERT INTO raw_teams VALUES(1,'Administrators'),(2,'Users'); INSERT INTO raw_users VALUES(1,1,'Ada',40),(2,1,'ADA',30),(3,2,'Bob',20),(4,2,'Other',10)");
        return new DbContext($pdo);
    }

    public function testConditionsAreGroupedAndComposeWithoutMutatingTheRoot(): void
    {
        $db = $this->fixture();
        $root = $db->set(WhereRawUser::class);
        $query = $root->whereRaw('id = ? OR team_id = ?', [1,2])->where(['age'=>['gte'=>15]]);
        self::assertSame(4, $root->count());
        self::assertSame([1,3], array_map(fn ($u) => $u->id, $query->orderBy(Expr::field('id'))->toArray()));
        self::assertSame(1, $query->orderByDescending(Expr::field('id'))->skip(1)->take(1)->first()->id);
        self::assertTrue($query->any());
        self::assertSame(2, $query->count());
        self::assertSame(EntityState::Detached, $db->entry($query->asNoTracking()->first())->state);
    }

    public function testNamedParametersRawSourcesAndMultipleConditionsCannotCollide(): void
    {
        $db = $this->fixture();
        $query = $db->set(WhereRawUser::class)
            ->fromSqlRaw('SELECT * FROM raw_users WHERE age > :p0', ['p0'=>0])
            ->whereRaw('id >= :p0', ['p0'=>2])
            ->whereRaw('team_id = :p0 OR team_id = :p0', [':p0'=>2])
            ->where(['age'=>['gt'=>0]])->orderBy(Expr::field('id'));
        self::assertSame([3,4], array_map(fn ($u) => $u->id, $query->toArray()));
    }

    public function testSubqueriesAndBoundValuesCannotInjectSql(): void
    {
        $db = $this->fixture();
        $root = $db->set(WhereRawUser::class);
        $users = $root->whereRaw('team_id NOT IN (SELECT id FROM raw_teams WHERE role = ?)', ['Administrators']);
        self::assertSame([3,4], array_map(fn ($u) => $u->id, $users->orderBy(Expr::field('id'))->toArray()));
        self::assertSame(0, $root->whereRaw('name = ?', ["' OR 1=1 --"])->count());
        self::assertSame(4, $root->count());
        self::assertSame(1, $root->whereRaw("name <> '?' AND id = :id /* ? :ignored */ -- trailing comment", ['id'=>3])->where(['team_id'=>2])->count());
    }

    public function testRawConditionsWorkForBulkUpdateAndDelete(): void
    {
        $db = $this->fixture();
        $root = $db->set(WhereRawUser::class);
        self::assertSame(2, $root->whereRaw('team_id IN (SELECT id FROM raw_teams WHERE role = ?)', ['Users'])->executeUpdate(['age'=>77]));
        self::assertSame(2, $root->whereRaw('age = :age', ['age'=>77])->executeDelete());
        self::assertSame(2, $root->count());
        self::assertSame([], $db->changeTracker->entries());
    }
}
