<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Integration;

use PDO;
use Light\Entity\{DbContext, Entity, EntityState, Table};
use Light\Entity\Tests\Support\MySqlDatabase;
use Light\Linq\Expr;
use PHPUnit\Framework\TestCase;

#[Table('users')]
final class FunctionUser extends Entity {}

final class MySqlFunctionTest extends TestCase
{
    use MySqlDatabase;

    private function functionDatabase(): PDO
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE users(user_id INT PRIMARY KEY, name VARCHAR(60))');
        $pdo->exec("INSERT INTO users VALUES(1,'Mary'),(9,'John'),(10,'Ada')");
        $pdo->exec('CREATE FUNCTION GetCustomScore(input_id INT) RETURNS INT DETERMINISTIC NO SQL RETURN input_id * 10');
        $pdo->exec('CREATE FUNCTION AddBonus(score_arg DECIMAL(12,2), bonus_arg DECIMAL(12,2)) RETURNS DECIMAL(12,2) DETERMINISTIC NO SQL RETURN score_arg + bonus_arg');
        $pdo->exec('CREATE FUNCTION EchoValue(value_arg TEXT) RETURNS TEXT DETERMINISTIC NO SQL RETURN value_arg');
        return $pdo;
    }

    public function testStoredFunctionExecutesWithSchemaInFilterOrderingAndProjection(): void
    {
        $pdo = $this->functionDatabase();
        $db = new DbContext($pdo);
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $db->registerFunction('GetCustomScore', schema: $schema, parameterCount: 1);
        $score = $db->function('getcustomscore', Expr::field('user_id'));
        $users = $db->set(FunctionUser::class)->where(Expr::gt($score, 80))->orderByDescending($score);
        $rows = $users->select(Expr::projection(['id'=>'user_id','score'=>$score]))->toArray();
        self::assertSame([['id'=>10,'score'=>100],['id'=>9,'score'=>90]], $rows);
        self::assertSame([], $db->changeTracker->entries());
        self::assertSame([100,90], $users->select($score)->toArray());
        self::assertSame(100, $users->select($score)->first());
        self::assertSame(2, $users->count());
        self::assertTrue($users->any());
        $entities = $users->toArray();
        self::assertSame([10,9], array_map(fn ($user) => $user->user_id, $entities));
        self::assertSame($entities[0], $db->set(FunctionUser::class)->find(10));
        self::assertCount(2, $db->changeTracker->entries());
    }

    public function testNestedFunctionsBindLiteralArgumentsAndSupportNoTracking(): void
    {
        $pdo = $this->functionDatabase();
        $db = new DbContext($pdo);
        $db->registerFunction('GetCustomScore', parameterCount: 1);
        $db->registerFunction('AddBonus', parameterCount: 2);
        $score = $db->function('AddBonus', $db->function('GetCustomScore', Expr::field('user_id')), 0.25);
        $users = $db->set(FunctionUser::class)->asNoTracking()->where(Expr::gt($score, 90.5));
        self::assertSame(['100.25'], $users->select($score)->toArray());
        $user = $users->first();
        self::assertSame(10, $user->user_id);
        self::assertSame(EntityState::Detached, $db->entry($user)->state);
        $user->name = 'Ignored';
        self::assertSame(0, $db->saveChanges());
        self::assertSame('Ada', $pdo->query('SELECT name FROM users WHERE user_id = 10')->fetchColumn());
    }

    public function testQuotedStringsAndNullRemainBoundFunctionArguments(): void
    {
        $pdo = $this->functionDatabase();
        $db = new DbContext($pdo);
        $db->registerFunction('EchoValue', parameterCount: 1);
        $payload = "x'); DROP TABLE users; --";
        $users = $db->set(FunctionUser::class);
        self::assertSame($payload, $users->select($db->function('EchoValue', $payload))->first());
        self::assertNull($users->select($db->function('EchoValue', null))->first());
        self::assertSame(3, $users->where(Expr::eq($db->function('EchoValue', null), null))->count());
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testDirectFunctionCallsNeedNoRegistration(): void
    {
        $pdo = $this->functionDatabase();
        $db = new DbContext($pdo);
        $score = $db->function('GetCustomScore', Expr::field('user_id'));
        $users = $db->set(FunctionUser::class)->where(Expr::gt($score, 80))->orderByDescending($score);
        self::assertSame([100,90], $users->select($score)->toArray());
        self::assertSame([10,9], array_map(fn ($user) => $user->user_id, $users->toArray()));
        self::assertSame('100.25', $users->select($db->function('AddBonus', $score, 0.25))->first());
        $db->registerFunction('GetCustomScore', schema: $pdo->query('SELECT DATABASE()')->fetchColumn(), parameterCount: 1);
        self::assertSame(10, $db->set(FunctionUser::class)->select($db->function('GetCustomScore', 1))->first());
        $this->expectException(\InvalidArgumentException::class);
        $db->function('GetCustomScore');
    }
}
