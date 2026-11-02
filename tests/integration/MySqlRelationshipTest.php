<?php
declare(strict_types=1);
namespace Light\Entity\Tests\Integration;

use Light\Entity\{DbContext, DynamicEntity, Entity, Table};
use Light\Entity\Tests\Support\MySqlDatabase;
use PHPUnit\Framework\TestCase;

#[Table('clients')]
final class RelationClient extends Entity {}

final class MySqlRelationshipTest extends TestCase
{
    use MySqlDatabase;

    public function testForeignKeysUniqueCompositeAndDynamicEntities(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE clients (region INT, id INT, PRIMARY KEY(region,id))');
        $pdo->exec('CREATE TABLE orders (id INT PRIMARY KEY, region INT, client_id INT, FOREIGN KEY(region,client_id) REFERENCES clients(region,id))');
        $pdo->exec('CREATE TABLE profiles (id INT PRIMARY KEY, region INT, client_id INT, UNIQUE(region,client_id), FOREIGN KEY(region,client_id) REFERENCES clients(region,id))');
        $pdo->exec('INSERT INTO clients VALUES (1,1),(2,1)');
        $pdo->exec('INSERT INTO orders VALUES (10,1,1),(11,1,1),(12,NULL,NULL)');
        $pdo->exec('INSERT INTO profiles VALUES (20,1,1)');
        $db = new DbContext($pdo);
        $client = $db->set(RelationClient::class)->find(['region'=>1, 'id'=>1]);
        self::assertSame(2, $client->getOrders()->count());
        $order = $client->getOrders()->first();
        self::assertInstanceOf(DynamicEntity::class, $order);
        self::assertSame($client, $order->getClient());
        self::assertSame(20, $client->getProfile()->id);
        $other = $db->set(RelationClient::class)->find(['region'=>2, 'id'=>1]);
        self::assertSame([], $other->getOrders()->toArray());
        self::assertNull($other->getProfile());
        $order->client_id = null;
        self::assertNull($order->getClient());
        self::assertSame(1, $db->saveChanges());
    }

    public function testIncludeLoadsNestedDynamicRowsAndPersistsUpdatesAndDeletes(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE clients (id INT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE orders (id INT PRIMARY KEY, client_id INT, status VARCHAR(30), FOREIGN KEY(client_id) REFERENCES clients(id))');
        $pdo->exec('CREATE TABLE notes (id INT PRIMARY KEY, order_id INT, body VARCHAR(30), FOREIGN KEY(order_id) REFERENCES orders(id))');
        $pdo->exec('INSERT INTO clients VALUES (1),(2),(3)');
        $pdo->exec("INSERT INTO orders VALUES (10,1,'pending'),(11,1,'pending'),(12,2,'pending'),(13,NULL,'pending')");
        $pdo->exec("INSERT INTO notes VALUES (20,10,'hello')");
        $db = new DbContext($pdo);
        $clients = $db->set(RelationClient::class)->include('Orders.Notes')->include('Orders.Client')
            ->orderBy(\Light\Linq\Expr::field('id'))->toArray();
        self::assertSame(2, $clients[0]->getOrders()->count());
        self::assertSame(1, $clients[1]->getOrders()->count());
        self::assertSame([], $clients[2]->getOrders()->toArray());
        $order = $clients[0]->getOrders()->first();
        self::assertSame($clients[0], $order->getClient());
        $notes = $order->getNotes();
        $note = $notes->first();
        self::assertSame('hello', $note->body);
        $note->body = 'updated';
        $order->status = 'completed';
        self::assertSame(2, $db->saveChanges());
        self::assertSame('completed', $pdo->query('SELECT status FROM orders WHERE id = '.$order->id)->fetchColumn());
        self::assertSame('updated', $pdo->query('SELECT body FROM notes WHERE id = 20')->fetchColumn());
        $notes->remove($note);
        self::assertSame(1, $db->saveChanges());
        $clients[0]->getOrders()->remove($order);
        self::assertSame(1, $db->saveChanges());
        self::assertSame(1, $clients[0]->getOrders()->count());
    }

    public function testIncludeCompositeKeysUniqueInverseAndPagination(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE clients (region INT, id INT, PRIMARY KEY(region,id))');
        $pdo->exec('CREATE TABLE orders (id INT PRIMARY KEY, region INT, client_id INT, FOREIGN KEY(region,client_id) REFERENCES clients(region,id))');
        $pdo->exec('CREATE TABLE profiles (id INT PRIMARY KEY, region INT, client_id INT, UNIQUE(region,client_id), FOREIGN KEY(region,client_id) REFERENCES clients(region,id))');
        $pdo->exec('INSERT INTO clients VALUES (1,1),(2,1),(2,2)');
        $pdo->exec('INSERT INTO orders VALUES (10,1,1),(11,2,2),(12,NULL,NULL)');
        $pdo->exec('INSERT INTO profiles VALUES (20,1,1)');
        $db = new DbContext($pdo);
        $clients = $db->set(RelationClient::class)->include('Orders.Client')->include('Profile')
            ->orderBy(\Light\Linq\Expr::field('region'))->toArray();
        $byKey = [];
        foreach ($clients as $client) { $byKey[$client->region.':'.$client->id] = $client; }
        self::assertSame(10, $byKey['1:1']->getOrders()->first()->id);
        self::assertSame($byKey['1:1'], $byKey['1:1']->getOrders()->first()->getClient());
        self::assertSame(20, $byKey['1:1']->getProfile()->id);
        self::assertSame([], $byKey['2:1']->getOrders()->toArray());
        self::assertNull($byKey['2:1']->getProfile());
        self::assertSame(11, $byKey['2:2']->getOrders()->first()->id);
        $paged = $db->set(RelationClient::class)->include('Orders')->orderBy(\Light\Linq\Expr::field('region'))->take(1)->toArray();
        self::assertCount(1, $paged);
        self::assertSame(1, $paged[0]->region);
    }

    public function testIncludeMatchesStringForeignKeysUsingDatabaseCollation(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE clients (id VARCHAR(30) COLLATE utf8mb4_unicode_ci PRIMARY KEY)');
        $pdo->exec('CREATE TABLE orders (id INT PRIMARY KEY, client_id VARCHAR(30) COLLATE utf8mb4_unicode_ci, FOREIGN KEY(client_id) REFERENCES clients(id))');
        $pdo->exec("INSERT INTO clients VALUES ('ABC')");
        $pdo->exec("INSERT INTO orders VALUES (1,'abc'),(2,'ABC')");
        $db = new DbContext($pdo);
        $client = $db->set(RelationClient::class)->include('Orders.Client')->find('ABC');
        self::assertSame(2, $client->getOrders()->count());
        foreach ($client->getOrders() as $order) {
            self::assertSame($client, $order->getClient());
        }
    }
}
