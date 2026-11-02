<?php
declare(strict_types=1);
namespace PhpEntity\Tests\Integration;

use PhpEntity\{DbContext, DynamicEntity, Entity, Table};
use PhpEntity\Tests\Support\MySqlDatabase;
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
}
