<?php

declare(strict_types=1);

namespace Light\Entity\Tests\SqliteFunctions;

use PDO;
use Light\Entity\{DbContext, Entity, EntityState, Table};
use Light\Linq\Expr;
use Light\Linq\Expressions\ValueExpression;
use PHPUnit\Framework\TestCase;

#[Table('users')]
final class ScoreUser extends Entity {}

final class ScoreContext extends DbContext
{
    public function getCustomScore(ValueExpression $userId): ValueExpression
    {
        return $this->function('GetCustomScore', $userId);
    }
}

final class SqliteFunctionTest extends TestCase
{
    private PDO $pdo;
    private ScoreContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE users(user_id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES(1,'Mary'),(9,'John'),(10,'Ada')");
        $createFunction = method_exists($this->pdo, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
        $this->pdo->$createFunction('GetCustomScore', fn ($id) => $id * 10, 1);
        $this->pdo->$createFunction('AddBonus', fn ($score, $bonus) => $score + $bonus, 2);
        $this->pdo->$createFunction('EchoValue', fn ($value) => $value, 1);
        $this->pdo->$createFunction('CurrentScore', fn () => 99, 0);
        $this->db = new ScoreContext($this->pdo);
        $this->db->registerFunction('GetCustomScore', parameterCount: 1);
        $this->db->registerFunction('AddBonus', parameterCount: 2);
        $this->db->registerFunction('EchoValue', parameterCount: 1);
        $this->db->registerFunction('CurrentScore', parameterCount: 0);
    }

    public function testWhereOrderingAndContextWrapperExecuteAgainstDatabase(): void
    {
        $score = $this->db->getCustomScore(Expr::field('user_id'));
        $users = $this->db->set(ScoreUser::class)->where(Expr::gt($score, 80))->orderByDescending($score)->toArray();
        self::assertSame([10,9], array_map(fn ($user) => $user->user_id, $users));
        self::assertCount(2, $this->db->changeTracker->entries());
        self::assertSame($users[0], $this->db->set(ScoreUser::class)->find(10));
    }

    public function testScalarAndNamedProjectionWithNestedFunctionAndConstants(): void
    {
        $score = $this->db->function('AddBonus', $this->db->function('GetCustomScore', Expr::field('user_id')), 5);
        $users = $this->db->set(ScoreUser::class)->orderBy(Expr::field('user_id'));
        self::assertSame([15,95,105], $users->select($score)->toArray());
        self::assertSame(15, $users->select($score)->first());
        self::assertSame([
            ['name'=>'Mary','score'=>15], ['name'=>'John','score'=>95], ['name'=>'Ada','score'=>105],
        ], $users->select(Expr::projection(['name'=>'name','score'=>$score]))->toArray());
        self::assertSame([], $this->db->changeTracker->entries());
        self::assertSame(99, $users->select($this->db->function('CurrentScore'))->first());
    }

    public function testNullAndStringConstantsStayBoundAndEmptyFirstStillThrows(): void
    {
        $users = $this->db->set(ScoreUser::class);
        $payload = "x'); DROP TABLE users; --";
        self::assertSame($payload, $users->select($this->db->function('EchoValue', $payload))->first());
        self::assertNull($users->select($this->db->function('EchoValue', null))->first());
        self::assertSame(3, $users->where(Expr::eq($this->db->function('EchoValue', null), null))->count());
        $empty = $users->where(Expr::gt($this->db->function('GetCustomScore', Expr::field('user_id')), 999));
        self::assertFalse($empty->any());
        self::assertSame([], $empty->select($this->db->function('CurrentScore'))->toArray());
        $this->expectException(\UnderflowException::class);
        $empty->select($this->db->function('CurrentScore'))->first();
    }

    public function testFunctionFilterWorksWithNoTrackingAndSaveBehavior(): void
    {
        $score = $this->db->function('GetCustomScore', Expr::field('user_id'));
        $user = $this->db->set(ScoreUser::class)->asNoTracking()->where(Expr::gt($score, 80))->first();
        self::assertSame(EntityState::Detached, $this->db->entry($user)->state);
        $user->name = 'Ignored';
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testFunctionMappingsAreScopedToContextAndDoNotCreateDatabaseFunctions(): void
    {
        $other = new DbContext($this->pdo);
        self::assertSame(10, $other->set(ScoreUser::class)->select($other->function('GetCustomScore', 1))->first());
        self::assertNull($other->function('GetCustomScore', 1)->definition->parameterCount);
        self::assertSame(1, $this->db->function('GetCustomScore', 1)->definition->parameterCount);
        $this->expectException(\PDOException::class);
        $other->set(ScoreUser::class)->select($other->function('NotInstalled'))->first();
    }

    public function testNumericComparisonsPreserveIntegerAndFloatTypes(): void
    {
        $score = $this->db->function('GetCustomScore', Expr::field('user_id'));
        $users = $this->db->set(ScoreUser::class);
        self::assertSame(2, $users->where(Expr::gt($score, 80))->count());
        self::assertSame(1, $users->where(Expr::gt($score, 90.5))->count());
        self::assertSame(1, $users->where(Expr::eq($score, 90.0))->count());
        $adjusted = $this->db->function('AddBonus', $score, 0.25);
        self::assertSame([10.25, 90.25, 100.25], $users->orderBy(Expr::field('user_id'))->select($adjusted)->toArray());
    }

    public function testDirectCallsWorkInFiltersNestedProjectionsAndParameterlessCalls(): void
    {
        $db = new DbContext($this->pdo);
        $score = $db->function('GetCustomScore', Expr::field('user_id'));
        $adjusted = $db->function('AddBonus', $score, 0.25);
        $users = $db->set(ScoreUser::class)->where(Expr::gt($score, 80))->orderByDescending($score);
        self::assertSame([100.25,90.25], $users->select($adjusted)->toArray());
        self::assertSame(99, $users->select($db->function('CurrentScore'))->first());
        self::assertSame([10,9], array_map(fn ($user) => $user->user_id, $users->toArray()));
        $payload = "x'); DROP TABLE users; --";
        self::assertSame($payload, $users->select($db->function('EchoValue', $payload))->first());
    }
}
