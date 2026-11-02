<?php
declare(strict_types=1);
namespace Light\Entity\Tests\Includes;

use PDO;
use PDOStatement;
use Light\Entity\{DbContext, DynamicEntity, Entity, Table};
use Light\Linq\Expr;
use PHPUnit\Framework\TestCase;

#[Table('clients')]
final class IncludeClient extends Entity {}
#[Table('orders')]
final class IncludeOrder extends Entity {}
#[Table('people')]
final class IncludePerson extends Entity {}

final class CountingPDO extends PDO
{
    public array $queries = [];
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return parent::prepare($query, $options);
    }
    public function reads(string $table): int
    {
        return count(array_filter($this->queries, fn ($sql) => (bool) preg_match('/^SELECT .* FROM "'.preg_quote($table, '/').'"(?: |$)/s', $sql)));
    }
}

final class IncludeTest extends TestCase
{
    private CountingPDO $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $this->pdo = new CountingPDO('sqlite::memory:');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT); CREATE TABLE orders (id INTEGER PRIMARY KEY, client_id INTEGER REFERENCES clients(id), status TEXT); CREATE TABLE notes (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders(id), body TEXT); CREATE TABLE profiles (id INTEGER PRIMARY KEY, client_id INTEGER UNIQUE REFERENCES clients(id))');
        $this->pdo->exec("INSERT INTO clients VALUES (1,'Mary'),(2,'John'),(3,'Ada'); INSERT INTO orders VALUES(10,1,'pending'),(11,1,'pending'),(12,2,'pending'),(13,NULL,'pending'); INSERT INTO notes VALUES(20,10,'hello'),(21,12,'world'); INSERT INTO profiles VALUES(30,1)");
        $this->db = new DbContext($this->pdo);
        $this->db->set(IncludeClient::class);
        $this->db->set(IncludeOrder::class);
    }

    public function testIncludeIsLazyAndLoadsOneCollectionQueryForAllParents(): void
    {
        $query = $this->db->set(IncludeClient::class)->include('Orders');
        self::assertSame(0, $this->pdo->reads('clients'));
        self::assertSame(0, $this->pdo->reads('orders'));
        $clients = $query->orderBy(Expr::field('id'))->toArray();
        self::assertSame(1, $this->pdo->reads('clients'));
        self::assertSame(1, $this->pdo->reads('orders'));
        self::assertSame([10,11], array_map(fn ($o) => $o->id, $clients[0]->getOrders()->toArray()));
        self::assertSame(1, $clients[1]->getOrders()->count());
        self::assertSame([], $clients[2]->getOrders()->toArray());
        self::assertFalse($clients[2]->getOrders()->any());
        self::assertTrue($clients[0]->getOrders()->any());
        self::assertSame(10, $clients[0]->getOrders()->first()->id);
        self::assertSame(1, $this->pdo->reads('orders'));
    }

    public function testSingularIncludePreservesIdentityAndCachesNull(): void
    {
        $orders = $this->db->set(IncludeOrder::class)->include('Client')->orderBy(Expr::field('id'))->toArray();
        self::assertSame($orders[0]->getClient(), $orders[1]->getClient());
        self::assertSame($this->db->set(IncludeClient::class)->find(1), $orders[0]->getClient());
        self::assertNull($orders[3]->getClient());
        self::assertSame(1, $this->pdo->reads('clients'));
        $orders[0]->client_id = 2;
        self::assertSame(2, $orders[0]->getClient()->id);
        self::assertSame(2, $this->pdo->reads('clients'));
    }

    public function testUniqueInverseIncludesEmptyAndDynamicRows(): void
    {
        $clients = $this->db->set(IncludeClient::class)->include('Profile')->orderBy(Expr::field('id'))->toArray();
        self::assertInstanceOf(DynamicEntity::class, $clients[0]->getProfile());
        self::assertSame(30, $clients[0]->getProfile()->id);
        self::assertNull($clients[1]->getProfile());
        self::assertNull($clients[2]->getProfile());
        self::assertSame(1, $this->pdo->reads('profiles'));
    }

    public function testNestedOverlappingPathsAreMergedAndDynamicEntitiesTracked(): void
    {
        $clients = $this->db->set(IncludeClient::class)
            ->include('Orders')->include('orders.Notes')->include('Orders.Notes')->include('Orders.Client')
            ->orderBy(Expr::field('id'))->toArray();
        $order = $clients[0]->getOrders()->first();
        $note = $order->getNotes()->first();
        self::assertSame($clients[0], $order->getClient());
        self::assertSame('hello', $note->body);
        self::assertSame([], $clients[0]->getOrders()->toArray()[1]->getNotes()->toArray());
        self::assertSame(1, $this->pdo->reads('orders'));
        self::assertSame(1, $this->pdo->reads('notes'));
        self::assertSame(2, $this->pdo->reads('clients'));
        $note->body = 'updated';
        $order->status = 'completed';
        self::assertSame(2, $this->db->saveChanges());
        self::assertSame('updated', $this->pdo->query('SELECT body FROM notes WHERE id = 20')->fetchColumn());
        self::assertSame('completed', $this->pdo->query('SELECT status FROM orders WHERE id = 10')->fetchColumn());
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testPreloadedMappedAndDynamicEntitiesCanBeDeleted(): void
    {
        $client = $this->db->set(IncludeClient::class)->include('Orders.Notes')->find(1);
        $orders = $client->getOrders();
        $order = $orders->first();
        $notes = $order->getNotes();
        $notes->remove($notes->first());
        self::assertSame(1, $this->db->saveChanges());
        $orders->remove($order);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame(1, $client->getOrders()->count());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders WHERE id = 10')->fetchColumn());
    }

    public function testPaginationLimitsParentsBeforeIncludingChildren(): void
    {
        $clients = $this->db->set(IncludeClient::class)->include('Orders')
            ->orderBy(Expr::field('id'))->skip(1)->take(1)->toArray();
        self::assertCount(1, $clients);
        self::assertSame(2, $clients[0]->id);
        self::assertSame(12, $clients[0]->getOrders()->first()->id);
        self::assertSame(1, $this->pdo->reads('orders'));
        $childQuery = array_values(array_filter($this->pdo->queries, fn ($sql) => str_starts_with($sql, 'SELECT * FROM "orders"')))[0];
        self::assertSame(1, substr_count($childQuery, '?'));
    }

    public function testFirstAndFindLoadIncludesEvenForTrackedParents(): void
    {
        $client = $this->db->set(IncludeClient::class)->find(1);
        self::assertSame($client, $this->db->set(IncludeClient::class)->include('Orders')->find(1));
        self::assertSame(2, $client->getOrders()->count());
        self::assertSame(1, $this->pdo->reads('orders'));
        $other = $this->db->set(IncludeClient::class)->include('Orders')->first(Expr::eq(Expr::field('id'), 2));
        self::assertSame(12, $other->getOrders()->first()->id);
    }

    public function testAdditionalOperatorsUseSqlRatherThanFilteringCachedResults(): void
    {
        $client = $this->db->set(IncludeClient::class)->include('Orders')->find(1);
        $orders = $client->getOrders();
        self::assertSame(11, $orders->where(Expr::eq(Expr::field('id'), 11))->first()->id);
        self::assertSame(11, $orders->orderByDescending(Expr::field('id'))->take(1)->first()->id);
        self::assertTrue($orders->any(Expr::eq(Expr::field('id'), 10)));
        self::assertSame(10, $orders->first(Expr::eq(Expr::field('id'), 10))->id);
        self::assertSame(5, $this->pdo->reads('orders'));
    }

    public function testEmptyParentsDoNotQueryChildrenAndEmptyFirstThrows(): void
    {
        $clients = $this->db->set(IncludeClient::class)->include('Orders')
            ->where(Expr::eq(Expr::field('id'), 99))->toArray();
        self::assertSame([], $clients);
        self::assertSame(0, $this->pdo->reads('orders'));
        $client = $this->db->set(IncludeClient::class)->include('Orders')->find(3);
        $this->expectException(\UnderflowException::class);
        $client->getOrders()->first();
    }

    public function testUnknownPathsFailEvenWithNoMatchingParents(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->db->set(IncludeClient::class)->include('Orders.Missing')->take(0)->toArray();
    }

    public function testInvalidPathRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->set(IncludeClient::class)->include('Orders..Notes');
    }

    public function testAmbiguityAndExplicitOverrides(): void
    {
        $this->pdo->exec('ALTER TABLE orders ADD COLUMN billing_client_id INTEGER REFERENCES clients(id)');
        $this->pdo->exec('UPDATE orders SET billing_client_id = 2 WHERE id = 10');
        $this->db = new DbContext($this->pdo);
        $this->db->set(IncludeClient::class);
        $this->db->set(IncludeOrder::class);
        try {
            $this->db->set(IncludeOrder::class)->include('Client')->toArray();
            self::fail('Expected ambiguity');
        } catch (\BadMethodCallException $e) { self::assertStringContainsString('Ambiguous', $e->getMessage()); }
        $this->db->relationship(IncludeOrder::class, 'Client', 'clients', ['client_id'], IncludeClient::class);
        $order = $this->db->set(IncludeOrder::class)->include('Client')->find(10);
        self::assertSame(1, $order->getClient()->id);
        $this->db->relationship(IncludeOrder::class, 'Client', 'clients', ['billing_client_id'], IncludeClient::class);
        self::assertSame(2, $order->getClient()->id);
        $this->db->relationship(IncludeClient::class, 'Purchases', 'orders', ['client_id'], IncludeOrder::class);
        $client = $this->db->set(IncludeClient::class)->include('Purchases')->find(1);
        self::assertSame(2, $client->getPurchases()->count());
    }

    public function testCompositeForeignKeysUseWholeTuplesAndUniqueInverse(): void
    {
        $this->pdo->exec('CREATE TABLE people (region INTEGER, id INTEGER, PRIMARY KEY(region,id)); CREATE TABLE passports (id INTEGER PRIMARY KEY, region INTEGER, person_id INTEGER, UNIQUE(region,person_id), FOREIGN KEY(region,person_id) REFERENCES people(region,id)); INSERT INTO people VALUES(1,2),(2,2),(2,3); INSERT INTO passports VALUES(1,1,2),(2,2,3)');
        $people = $this->db->set(IncludePerson::class)->include('Passport')->orderBy(Expr::field('region'))->orderBy(Expr::field('id'))->toArray();
        self::assertSame(1, $people[0]->getPassport()->id);
        self::assertNull($people[1]->getPassport());
        self::assertSame(2, $people[2]->getPassport()->id);
        self::assertSame(1, $this->pdo->reads('passports'));
        $passport = $people[0]->getPassport();
        $this->db->relationship(DynamicEntity::class, 'Person', 'people', ['region','person_id'], IncludePerson::class);
        $loaded = $people[0]->getPassport(); // Configuration invalidates previous caches.
        self::assertSame($passport, $loaded);
        self::assertSame($people[0], $loaded->getPerson());
    }

    public function testLargeParentSetsAreChunked(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO clients VALUES (?,?)');
        for ($i = 4; $i <= 405; ++$i) { $insert->execute([$i, 'Client']); }
        $clients = $this->db->set(IncludeClient::class)->include('Orders')->toArray();
        self::assertCount(405, $clients);
        foreach ($clients as $client) { $client->getOrders()->toArray(); }
        self::assertSame(3, $this->pdo->reads('orders'));
    }

    public function testSuccessfulSaveInvalidatesPreviouslyLoadedEmptyCollection(): void
    {
        $client = $this->db->set(IncludeClient::class)->include('Orders')->find(3);
        self::assertSame([], $client->getOrders()->toArray());
        $order = new IncludeOrder();
        $order->client_id = 3;
        $order->status = 'new';
        $this->db->set(IncludeOrder::class)->add($order);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame([$order], $client->getOrders()->toArray());
    }

    public function testStringForeignKeysFollowDatabaseCollationInBothDirections(): void
    {
        $this->pdo = new CountingPDO('sqlite::memory:');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec("CREATE TABLE clients (id TEXT COLLATE NOCASE PRIMARY KEY); CREATE TABLE orders (id INTEGER PRIMARY KEY, client_id TEXT COLLATE NOCASE REFERENCES clients(id)); INSERT INTO clients VALUES ('ABC'); INSERT INTO orders VALUES (1,'abc'),(2,'ABC')");
        $db = new DbContext($this->pdo);
        $db->set(IncludeClient::class);
        $orders = $db->set(IncludeOrder::class)->include('Client')->orderBy(Expr::field('id'))->toArray();
        self::assertSame('ABC', $orders[0]->getClient()->id);
        self::assertSame($orders[0]->getClient(), $orders[1]->getClient());
        self::assertSame(1, $this->pdo->reads('clients'));
        $client = $db->set(IncludeClient::class)->include('Orders')->find('ABC');
        self::assertSame($orders, $client->getOrders()->toArray());
        self::assertSame(2, $client->getOrders()->count());
        self::assertSame(2, $this->pdo->reads('orders'));
    }

    public function testIncludeDoesNotOverwritePendingChangesOnTrackedTargets(): void
    {
        $order = $this->db->set(IncludeOrder::class)->find(10);
        $order->status = 'edited';
        $client = $this->db->set(IncludeClient::class)->include('Orders')->find(1);
        self::assertSame($order, $client->getOrders()->first());
        self::assertSame('edited', $client->getOrders()->first()->status);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('edited', $this->pdo->query('SELECT status FROM orders WHERE id = 10')->fetchColumn());
    }
}
