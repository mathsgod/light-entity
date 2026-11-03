<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\Sql\MySqlDialect;
use Light\Entity\Sql\PostgreSqlDialect;
use Light\Entity\Sql\SqliteDialect;
use Light\Entity\Sql\SqlQueryCompiler;
use Light\Entity\Sql\SqlServerDialect;
use Light\Entity\SqlQueryProvider;
use Light\Linq\Expr;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ClientSequenceOperatorsTest extends TestCase
{
    public function testSqlDialectsRejectNewSequenceOperatorsWithExplicitClientBoundary(): void
    {
        $source = Queryable::from(new InMemoryQueryProvider([]), 'users');
        $queries = [
            'groupJoin' => $source->groupJoin($source, Expr::field('id'), Expr::field('id'), Expr::field('inner')),
            'zip' => $source->zip($source, Expr::field('outer')),
            'chunk' => $source->chunk(2),
        ];
        foreach ([new SqliteDialect(), new MySqlDialect(), new PostgreSqlDialect(), new SqlServerDialect()] as $dialect) {
            foreach ($queries as $method => $query) {
                // Also exercise nested operators, rather than silently translating a prefix.
                foreach ([$query, $query->take(1)] as $composed) {
                    try {
                        (new SqlQueryCompiler($dialect))->compile($composed->expression());
                        self::fail('Expected unsupported SQL operator');
                    } catch (\LogicException $error) {
                        self::assertStringContainsString($method.'()', $error->getMessage());
                        self::assertStringContainsString('asEnumerable()', $error->getMessage());
                    }
                }
            }
        }
    }

    public function testSqlProviderDoesNotSilentlyExecuteUnsupportedOperationsInPhp(): void
    {
        $provider = new SqlQueryProvider($this->connection(), new SqliteDialect());
        $query = Queryable::from($provider, 'missing_table')->chunk(2);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('asEnumerable()');
        $query->toArray();
    }

    private function connection(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }
        return new PDO('sqlite::memory:');
    }

    public function testExplicitClientEvaluationWorksAfterFilteringAndOrderingInSql(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE users (id INTEGER, name TEXT)');
        $pdo->exec("INSERT INTO users VALUES (3, 'Cat'), (1, 'Ada'), (2, 'Bob')");
        $pdo->exec('CREATE TABLE orders (id INTEGER, user_id INTEGER)');
        $pdo->exec('INSERT INTO orders VALUES (10, 1), (11, 1), (12, 3)');
        $provider = new SqlQueryProvider($pdo, new SqliteDialect());
        $users = Queryable::from($provider, 'users')->where(Expr::lt(Expr::field('id'), 3))
            ->orderBy(Expr::field('id'))->asEnumerable();
        $orders = Queryable::from($provider, 'orders')->orderBy(Expr::field('id'))->asEnumerable();
        self::assertSame([
            [['id' => 1, 'name' => 'Ada']], [['id' => 2, 'name' => 'Bob']],
        ], $users->chunk(1)->toArray());
        self::assertSame([['Ada', 10], ['Bob', 11]],
            $users->zip($orders, fn ($user, $order) => [$user['name'], $order['id']])->toArray());
        self::assertSame([['Ada', [10, 11]], ['Bob', []]], $users->groupJoin(
            $orders,
            fn ($user) => $user['id'],
            fn ($order) => $order['user_id'],
            fn ($user, $matches) => [$user['name'], $matches->select(fn ($order) => $order['id'])->toArray()],
        )->toArray());
    }
}
