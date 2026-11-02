<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Integration;

use Light\Entity\{DbContext, Entity, EntityState, QueryTrackingBehavior, Table};
use Light\Entity\Tests\Support\MySqlDatabase;
use PHPUnit\Framework\TestCase;

#[Table('users')]
final class MySqlReadUser extends Entity {}
#[Table('clients')]
final class MySqlReadClient extends Entity {}
#[Table('orders')]
final class MySqlReadOrder extends Entity {}

final class MySqlQueryTrackingTest extends TestCase
{
    use MySqlDatabase;

    public function testNoTrackingBypassesIdentityAndSnapshotsWhileExplicitTrackingStillSaves(): void
    {
        $pdo = $this->connection();
        $pdo->exec("CREATE TABLE users(user_id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60), label VARCHAR(80) GENERATED ALWAYS AS (CONCAT(name,'!')) STORED)");
        $pdo->exec("INSERT INTO users(name) VALUES ('Mary'),('John')");
        $db = new DbContext($pdo);
        $users = $db->set(MySqlReadUser::class);
        $tracked = $users->find(1);
        $tracked->name = 'Pending';
        $read = $users->asNoTracking()->find(1);
        self::assertNotSame($tracked, $read);
        self::assertSame('Mary', $read->name);
        self::assertSame('Mary!', $read->label);
        self::assertSame(EntityState::Detached, $db->entry($read)->state);
        self::assertNotSame($read, $users->asNoTracking()->find(1));
        self::assertCount(1, $db->changeTracker->entries());
        $read->name = 'Ignored';
        self::assertSame(1, $db->saveChanges());
        self::assertSame('Pending', $pdo->query('SELECT name FROM users WHERE user_id = 1')->fetchColumn());
        $db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::NoTracking;
        self::assertSame(EntityState::Detached, $db->entry($users->find(2))->state);
        $john = $users->asTracking()->find(2);
        self::assertSame(EntityState::Unchanged, $db->entry($john)->state);
        $john->name = 'Saved';
        self::assertSame(1, $db->saveChanges());
    }

    public function testNoTrackingNestedIncludesAndLazyGettersRemainDetached(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE clients(id INT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE orders(id INT PRIMARY KEY, client_id INT, status VARCHAR(30), FOREIGN KEY(client_id) REFERENCES clients(id))');
        $pdo->exec('CREATE TABLE notes(id INT PRIMARY KEY, order_id INT, body VARCHAR(60), FOREIGN KEY(order_id) REFERENCES orders(id))');
        $pdo->exec('INSERT INTO clients VALUES(1),(2)');
        $pdo->exec("INSERT INTO orders VALUES(10,1,'pending'),(11,1,'pending')");
        $pdo->exec("INSERT INTO notes VALUES(20,10,'note')");
        $db = new DbContext($pdo);
        $db->set(MySqlReadOrder::class);
        $client = $db->set(MySqlReadClient::class)->asNoTracking()->include('Orders.Notes')->include('Orders.Client')->find(1);
        $order = $client->getOrders()->first();
        $note = $order->getNotes()->first();
        self::assertSame(2, $client->getOrders()->count());
        self::assertSame($order, $client->getOrders()->first());
        self::assertNotSame($client, $order->getClient());
        foreach ([$client, $order, $note, $order->getClient()] as $entity) {
            self::assertSame(EntityState::Detached, $db->entry($entity)->state);
        }
        self::assertSame([], $db->changeTracker->entries());
        $note->body = 'Ignored';
        self::assertSame(0, $db->saveChanges());
        self::assertSame('note', $pdo->query('SELECT body FROM notes')->fetchColumn());
        $lazy = $db->set(MySqlReadClient::class)->asNoTracking()->find(1)->getOrders()->first();
        self::assertSame(EntityState::Detached, $db->entry($lazy)->state);
        self::assertSame(EntityState::Detached, $db->entry($lazy->getNotes()->first())->state);
        $tracked = $client->getOrders()->asTracking()->first();
        self::assertNotSame($order, $tracked);
        self::assertSame(EntityState::Unchanged, $db->entry($tracked)->state);
        $tracked->status = 'completed';
        self::assertSame(1, $db->saveChanges());
        self::assertSame(EntityState::Detached, $db->entry($client->getOrders()->first())->state);
    }

    public function testNoTrackingCompositeIncludesAndExplicitDynamicDeletion(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE clients(region INT, id INT, PRIMARY KEY(region,id))');
        $pdo->exec('CREATE TABLE orders(id INT PRIMARY KEY, region INT, client_id INT, FOREIGN KEY(region,client_id) REFERENCES clients(region,id))');
        $pdo->exec('INSERT INTO clients VALUES(1,1),(2,1)');
        $pdo->exec('INSERT INTO orders VALUES(10,1,1)');
        $db = new DbContext($pdo);
        $db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::NoTracking;
        $client = $db->set(MySqlReadClient::class)->include('Orders')->find(['region'=>1, 'id'=>1]);
        $orders = $client->getOrders();
        $order = $orders->first(); // DynamicEntity: no application order class registered.
        self::assertSame(EntityState::Detached, $db->entry($order)->state);
        self::assertSame([], $db->changeTracker->entries());
        self::assertSame([], $db->set(MySqlReadClient::class)->include('Orders')->find(['region'=>2,'id'=>1])->getOrders()->toArray());
        $orders->remove($order); // An explicit write operation opts into tracking.
        self::assertSame(EntityState::Deleted, $db->entry($order)->state);
        self::assertSame(1, $db->saveChanges());
        self::assertSame(EntityState::Detached, $db->entry($order)->state);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }
}
