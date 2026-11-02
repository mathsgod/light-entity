<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use PDO;
use Light\Entity\DbContext;
use Light\Entity\Entity;
use Light\Entity\Sql\MySqlDialect;
use Light\Entity\Sql\PostgreSqlDialect;
use Light\Entity\Sql\SqliteDialect;
use Light\Entity\Sql\SqlServerDialect;
use Light\Entity\Sql\SqlQueryCompiler;
use Light\Entity\SqlQueryProvider;
use Light\Linq\Expr;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

class JoinTest extends TestCase
{
    public function testEveryDialectCompilesParameterizedFilteredJoins(): void
    {
        $provider = new InMemoryQueryProvider([]);
        $orders = Queryable::from($provider, 'orders')->where(Expr::gt(Expr::field('id'), 1));
        $customers = Queryable::from($provider, 'customers')->where(Expr::neq(Expr::field('name'), 'Bob'));
        $query = $orders->leftJoin($customers, Expr::field('customerId'), Expr::field('id'),
            Expr::projection(['id' => 'outer.id', 'name' => 'inner.name', 'tag' => Expr::value('result')]))
            ->where(Expr::neq(Expr::field('name'), null))->orderBy(Expr::field('id'))->take(5);
        foreach ([new MySqlDialect(), new PostgreSqlDialect(), new SqliteDialect(), new SqlServerDialect()] as $dialect) {
            $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
            self::assertStringContainsString(' LEFT JOIN ', $compiled->sql);
            self::assertStringContainsString($dialect->quoteIdentifier('outer.customerId').' = '.$dialect->quoteIdentifier('inner.id'), $compiled->sql);
            self::assertStringContainsString(' IS NOT NULL', $compiled->sql);
            self::assertSame(['p0' => 'result', 'p1' => 1, 'p2' => 'Bob'], $compiled->parameters);
            self::assertStringNotContainsString("'Bob'", $compiled->sql);
        }
    }

    public function testCompositeAndNullKeysCompileAsEquality(): void
    {
        $provider = new InMemoryQueryProvider([]);
        $source = Queryable::from($provider, 'orders');
        $query = $source->join($source, Expr::projection(['id' => 'id', 'other' => Expr::value(null)]),
            Expr::projection(['id' => 'id', 'other' => Expr::value(null)]), Expr::field('outer.id'));
        $compiled = (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression());
        self::assertStringContainsString('("outer"."id" = "inner"."id") AND (:p0 = :p1)', $compiled->sql);
        self::assertSame(['p0' => null, 'p1' => null], $compiled->parameters);
    }

    public function testMismatchedCompositeKeysAreRejected(): void
    {
        $source = Queryable::from(new InMemoryQueryProvider([]), 'orders');
        $query = $source->join($source, Expr::fields('id'), Expr::fields('customerId'), Expr::field('outer.id'));
        $this->expectException(\InvalidArgumentException::class);
        (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression());
    }

    public function testScalarJoinSourcesAreRejectedInsteadOfGeneratingInvalidColumns(): void
    {
        $source = Queryable::from(new InMemoryQueryProvider([]), 'orders');
        $query = $source->join($source->select(Expr::field('id')),
            Expr::field('id'), Expr::field('id'), Expr::field('outer.id'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Join sources must return rows');
        (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression());
    }

    public function testSqlServerDropsUnpagedSourceOrdering(): void
    {
        $source = Queryable::from(new InMemoryQueryProvider([]), 'orders')->orderBy(Expr::field('id'));
        $query = $source->join($source, Expr::field('id'), Expr::field('id'), Expr::field('outer.id'));
        $sql = (new SqlQueryCompiler(new SqlServerDialect()))->compile($query->expression())->sql;
        self::assertStringNotContainsString('ORDER BY', $sql);
    }

    public function testJoinExecutionAndTerminalOperators(): void
    {
        $pdo = $this->database();
        $provider = new SqlQueryProvider($pdo, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? new MySqlDialect() : new SqliteDialect());
        $orders = Queryable::from($provider, 'orders');
        $customers = Queryable::from($provider, 'customers');
        $query = $orders->leftJoin($customers, Expr::field('customerId'), Expr::field('id'),
            Expr::projection(['id' => 'outer.id', 'name' => 'inner.name']));
        self::assertSame([
            ['id' => 1, 'name' => 'Ada'], ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'], ['id' => 3, 'name' => null], ['id' => 4, 'name' => null],
        ], $query->orderBy(Expr::field('id'))->orderBy(Expr::field('name'))->toArray());
        self::assertSame(5, $query->count());
        self::assertTrue($query->any(Expr::eq(Expr::field('name'), null)));
        self::assertSame(['id' => 2, 'name' => 'Bob'], $query->first(Expr::eq(Expr::field('id'), 2)));
        self::assertSame(['Bob'], $query->where(Expr::eq(Expr::field('id'), 2))->select(Expr::field('name'))->toArray());
        $inner = $orders->join($customers, Expr::field('customerId'), Expr::field('id'), Expr::field('inner.name'));
        self::assertSame(3, $inner->count());
    }

    public function testDbSetJoinProjectsWithoutCorruptingTrackingAndRejectsOtherContexts(): void
    {
        $pdo = $this->database();
        $db = new DbContext($pdo);
        $orders = $db->set(JoinOrder::class, 'orders');
        $customers = $db->set(JoinCustomer::class, 'customers');
        $tracked = $orders->find(1);
        $rows = $orders->where(Expr::eq(Expr::field('id'), 1))->join($customers,
            Expr::field('customerId'), Expr::field('id'), Expr::fields('outer.id', 'inner.name'))->toArray();
        self::assertCount(2, $rows);
        self::assertSame($tracked, $orders->find(1));
        self::assertSame(0, $db->saveChanges());
        $this->expectException(\InvalidArgumentException::class);
        $orders->join((new DbContext($pdo))->set(JoinCustomer::class, 'customers'),
            Expr::field('customerId'), Expr::field('id'), Expr::field('outer.id'));
    }

    public function testMatchesInMemoryForComposedJoins(): void
    {
        $pdo = $this->database();
        $memory = new InMemoryQueryProvider([
            'orders' => $pdo->query('SELECT * FROM orders')->fetchAll(PDO::FETCH_ASSOC),
            'customers' => $pdo->query('SELECT * FROM customers')->fetchAll(PDO::FETCH_ASSOC),
        ]);
        $sql = new SqlQueryProvider($pdo, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? new MySqlDialect() : new SqliteDialect());
        $results = [];
        foreach ([$memory, $sql] as $provider) {
            $orders = Queryable::from($provider, 'orders');
            $customers = Queryable::from($provider, 'customers');
            $projection = Expr::projection(['id' => 'outer.id', 'name' => 'inner.name']);
            $left = $orders->leftJoin($customers, Expr::field('customerId'), Expr::field('id'), $projection);
            $queries = [
                $orders->where(Expr::gt(Expr::field('id'), 1))->leftJoin(
                    $customers->where(Expr::neq(Expr::field('name'), 'Bob')),
                    Expr::field('customerId'), Expr::field('id'), $projection),
                $orders->orderByDescending(Expr::field('id'))->take(2)->leftJoin(
                    $customers->orderBy(Expr::field('rowId'))->take(1),
                    Expr::field('customerId'), Expr::field('id'), $projection),
                $orders->leftJoin($customers->take(0), Expr::field('customerId'), Expr::field('id'), $projection),
                $orders->join($customers->take(0), Expr::field('customerId'), Expr::field('id'), $projection),
                $left->join($orders, Expr::field('id'), Expr::field('id'),
                    Expr::projection(['id' => 'outer.id', 'name' => 'outer.name'])),
                $orders->join($orders, Expr::projection(['id' => 'id', 'customer' => 'customerId']),
                    Expr::projection(['id' => 'id', 'customer' => 'customerId']),
                    Expr::projection(['id' => 'outer.id', 'name' => Expr::value('self')])),
                $left->where(Expr::eq(Expr::field('id'), 2))->take(1)
                    ->join($customers, Expr::field('name'), Expr::field('name'), $projection),
                $left->take(0)->where(Expr::eq(Expr::field('id'), 1)),
                $orders->select(Expr::projection(['id' => 'id', 'key' => 'customerId']))->join(
                    $customers->select(Expr::fields('id', 'name')),
                    Expr::field('key'), Expr::field('id'), $projection),
            ];
            $sets = [];
            foreach ($queries as $query) {
                $rows = $query->toArray();
                usort($rows, fn ($a, $b) => [$a['id'], $a['name']] <=> [$b['id'], $b['name']]);
                $sets[] = $rows;
            }
            $sets[] = $orders->join($customers, Expr::field('customerId'), Expr::field('id'),
                Expr::field('inner.name'))->first();
            $sets[] = $left->skip(1)->take(2)->count();
            $sets[] = $left->where(Expr::eq(Expr::field('name'), "' OR 1=1 --"))->any();
            $results[] = $sets;
        }
        self::assertSame($results[0], $results[1]);
        self::assertCount(3, $results[1][8]);
        self::assertSame(2, $results[1][10]);
        self::assertFalse($results[1][11]);
    }

    public function testDbSetLeftJoinAndRawProjection(): void
    {
        $db = new DbContext($this->database());
        $orders = $db->set(JoinOrder::class, 'orders');
        $customers = $db->set(JoinCustomer::class, 'customers');
        $query = $orders->leftJoin($customers, Expr::field('customerId'), Expr::field('id'),
            Expr::projection(['id' => 'outer.id', 'name' => 'inner.name']));
        self::assertSame(2, $query->where(Expr::eq(Expr::field('name'), null))->count());
        self::assertSame(5, $query->join($orders->asQueryable(), Expr::field('id'), Expr::field('id'),
            Expr::field('outer.id'))->count());
        self::assertSame([1, 2, 3, 4], $orders->orderBy(Expr::field('id'))->select(Expr::field('id'))->toArray());
    }

    protected function connection(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }
        return new PDO('sqlite::memory:');
    }

    private function database(): PDO
    {
        $pdo = $this->connection();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customerId INTEGER)');
        $identity = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' AUTO_INCREMENT' : '';
        $pdo->exec('CREATE TABLE customers (rowId INTEGER PRIMARY KEY'.$identity.', id INTEGER, name TEXT)');
        $pdo->exec('INSERT INTO orders VALUES (1,10),(2,20),(3,NULL),(4,99)');
        $pdo->exec("INSERT INTO customers (id,name) VALUES (10,'Ada'),(10,'Alice'),(20,'Bob'),(NULL,'Null key')");
        return $pdo;
    }
}

final class JoinOrder extends Entity {}
final class JoinCustomer extends Entity {}
