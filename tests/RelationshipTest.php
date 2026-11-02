<?php
declare(strict_types=1);
namespace Light\Entity\Tests\Relationships;

use PDO;
use Light\Entity\{DbContext, DbSet, DynamicEntity, Entity, Table};
use Light\Linq\Expr;
use PHPUnit\Framework\TestCase;

final class Client extends Entity {}
final class Order extends Entity {}
#[Table('people')]
final class Person extends Entity {}

final class RelationshipTest extends TestCase
{
    private PDO $pdo;
    private DbContext $db;
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY); CREATE TABLE orders (id INTEGER PRIMARY KEY, client_id INTEGER REFERENCES clients(id)); CREATE TABLE notes (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders(id), body TEXT); CREATE TABLE profiles (id INTEGER PRIMARY KEY, client_id INTEGER UNIQUE REFERENCES clients(id)); CREATE TABLE audit_log (message TEXT)');
        $this->pdo->exec("INSERT INTO clients VALUES (1),(2); INSERT INTO orders VALUES (10,1),(11,1),(12,NULL); INSERT INTO notes VALUES (20,10,'hello'); INSERT INTO profiles VALUES (30,1)");
        $this->db = new DbContext($this->pdo);
    }
    public function testCollectionAndRegisteredClassIdentity(): void
    {
        $order = $this->db->set(Order::class)->find(10);
        $client = $this->db->set(Client::class)->find(1);
        self::assertInstanceOf(DbSet::class, $client->getOrders());
        self::assertSame(2, $client->getOrders()->count());
        self::assertSame($order, $client->getOrders()->where(Expr::eq(Expr::field('id'), 10))->first());
        self::assertSame($client, $order->getClient());
    }
    public function testConventionalClassDiscovery(): void
    {
        $client = $this->db->set(Client::class)->find(1);
        self::assertInstanceOf(Order::class, $client->getOrders()->first());
    }
    public function testDynamicRowsAreTrackedAndSupportNavigation(): void
    {
        $order = $this->db->set(Order::class)->find(10);
        $note = $order->getNotes()->first();
        self::assertInstanceOf(DynamicEntity::class, $note);
        self::assertSame($order, $note->getOrder());
        self::assertSame($note, $order->getNotes()->first());
        $note->body = 'changed';
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('changed', $this->pdo->query('SELECT body FROM notes')->fetchColumn());
    }
    public function testMissingRowsAndUniqueInverse(): void
    {
        $client = $this->db->set(Client::class)->find(2);
        self::assertSame([], $client->getOrders()->toArray());
        self::assertNull($client->getProfile());
        self::assertNull($this->db->set(Order::class)->find(12)->getClient());
        self::assertInstanceOf(DynamicEntity::class, $this->db->set(Client::class)->find(1)->getProfile());
    }
    public function testFkChangesAreReadWithoutStaleResultCache(): void
    {
        $order = $this->db->set(Order::class)->find(10);
        self::assertSame(1, $order->getClient()->id);
        $order->client_id = 2;
        self::assertSame(2, $order->getClient()->id);
    }
    public function testUnknownGetterThrows(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->db->set(Client::class)->find(1)->getFoo();
    }
    public function testAmbiguityRequiresExplicitForeignKey(): void
    {
        $this->pdo->exec('ALTER TABLE orders ADD COLUMN billing_client_id INTEGER REFERENCES clients(id)');
        $order = $this->db->set(Order::class)->find(10);
        try { $order->getClient(); self::fail('Expected ambiguity'); }
        catch (\BadMethodCallException $e) { self::assertStringContainsString('Ambiguous', $e->getMessage()); }
        $order->billing_client_id = 2;
        self::assertSame(2, $order->getBillingClient()->id);
        $this->db->relationship(Order::class, 'Client', 'clients', ['client_id'], Client::class);
        self::assertSame(1, $order->getClient()->id);
        $this->db->relationship(Client::class, 'Purchases', 'orders', ['client_id'], Order::class);
        self::assertSame(2, $order->getClient()->getPurchases()->count());
    }
    public function testCompositeForeignKeyAndUniqueConstraint(): void
    {
        $this->pdo->exec('CREATE TABLE people (region INTEGER, id INTEGER, PRIMARY KEY (region,id)); CREATE TABLE passports (id INTEGER PRIMARY KEY, region INTEGER, person_id INTEGER, UNIQUE(region,person_id), FOREIGN KEY(region,person_id) REFERENCES people(region,id)); INSERT INTO people VALUES(1,2),(2,2); INSERT INTO passports VALUES(1,1,2)');
        $person = $this->db->set(Person::class)->find(['region'=>1, 'id'=>2]);
        $passport = $person->getPassport();
        self::assertSame(1, $passport->id);
        self::assertSame($person, $passport->getPerson());
        self::assertNull($this->db->set(Person::class)->find(['region'=>2, 'id'=>2])->getPassport());
    }
    public function testUnsavedEntitiesDoNotMatchNullForeignKeys(): void
    {
        $client = new Client();
        $this->db->set(Client::class)->add($client);
        self::assertSame([], $client->getOrders()->toArray());
    }
}
