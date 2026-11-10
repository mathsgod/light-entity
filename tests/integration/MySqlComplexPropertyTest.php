<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Integration;

use Light\Entity\{DbContext, Entity, EntityState, ModelBuilder, Table};
use Light\Entity\Tests\Support\MySqlDatabase;
use Light\Linq\Expr;
use PHPUnit\Framework\TestCase;

class MySqlComplexAddress
{
    public function __construct(public string $city, public string $street, public ?string $zip = null) {}
}
#[Table('customers')]
final class MySqlComplexCustomer extends Entity { public MySqlComplexAddress $address; }
#[Table('orders')]
final class MySqlComplexOrder extends Entity {}
#[Table('parties')]
class MySqlComplexParty extends Entity { public MySqlComplexAddress $address; }
final class MySqlComplexMember extends MySqlComplexParty {}
final class MySqlComplexContext extends DbContext
{
    protected function onModelCreating(ModelBuilder $builder): void
    {
        $builder->entity(MySqlComplexCustomer::class)->complexProperty('address');
        $builder->entity(MySqlComplexParty::class)->complexProperty('address');
    }
}

final class MySqlComplexPropertyTest extends TestCase
{
    use MySqlDatabase;

    private function fixture(): array
    {
        $pdo = $this->connection();
        $pdo->exec("CREATE TABLE customers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60), address_city VARCHAR(60) NOT NULL, address_street VARCHAR(60) NOT NULL DEFAULT 'Default', address_zip VARCHAR(20))");
        $pdo->exec("INSERT INTO customers VALUES (1,'Ada','Hong Kong','First',NULL),(2,'Bob','London','Second','SW1'),(3,'Cat','Hong Kong','Third',NULL)");
        return [$pdo, new MySqlComplexContext($pdo)];
    }

    public function testComplexReadWriteSnapshotsAndDatabaseDefaults(): void
    {
        [$pdo, $db] = $this->fixture();
        $customer = $db->set(MySqlComplexCustomer::class)->find(1);
        self::assertSame('Hong Kong', $customer->address->city);
        $address = $customer->address;
        $address->city = 'Tokyo';
        self::assertSame('Hong Kong', $db->entry($customer)->originalValues['address_city']);
        self::assertSame(EntityState::Modified, $db->entry($customer)->state);
        self::assertSame(1, $db->saveChanges());
        self::assertSame($address, $customer->address);
        self::assertSame('Tokyo', $pdo->query('SELECT address_city FROM customers WHERE id=1')->fetchColumn());
        $new = new MySqlComplexCustomer();
        $new->address = (new \ReflectionClass(MySqlComplexAddress::class))->newInstanceWithoutConstructor();
        $new->address->city = 'Paris';
        $db->set(MySqlComplexCustomer::class)->add($new);
        self::assertSame(1, $db->saveChanges());
        self::assertSame('Default', $new->address->street);
        self::assertSame(0, $db->saveChanges());
    }

    public function testMemberQueriesSubqueriesJoinsAndBulkUpdates(): void
    {
        [, $db] = $this->fixture();
        $set = $db->set(MySqlComplexCustomer::class);
        self::assertSame([3, 1], $set->where(fn ($c) => $c->address->city == 'Hong Kong')
            ->orderByDescending(fn ($c) => $c->address->street)
            ->select(fn ($c) => $c->id)->toArray());
        self::assertSame(2, $set->where(fn ($c) => $set->any(fn ($o) => $o->address->city == $c->address->city && $o->id != $c->id))->count());
        self::assertSame(2, $set->where(fn ($c) =>
            ($set->any(fn ($o) => $o->address->city == $c->address->city && $o->id != $c->id)
                ? $c->address->city : '') == 'Hong Kong')->count());
        self::assertCount(5, $set->join($set,
            fn ($c) => $c->address->city,
            fn ($c) => $c->address->city,
            Expr::projection(['a' => 'outer.address.city', 'b' => 'inner.address.city']))->toArray());
        self::assertSame(2, $set->where(['address.city' => 'Hong Kong'])->executeUpdate(['address.city' => 'Tokyo']));
        self::assertSame(['Tokyo', 'London', 'Tokyo'], $set->orderBy(Expr::field('id'))->select(fn ($c) => $c->address->city)->toArray());
    }

    public function testNoTrackingAndIncludeReconstructValueObjects(): void
    {
        [$pdo, $db] = $this->fixture();
        $rows = $db->set(MySqlComplexCustomer::class)->asNoTracking()->orderBy(Expr::field('id'))->toArray();
        self::assertSame('Hong Kong', $rows[0]->address->city);
        self::assertNotSame($rows[0]->address, $db->set(MySqlComplexCustomer::class)->asNoTracking()->find(1)->address);
        $pdo->exec('CREATE TABLE orders (id INT PRIMARY KEY, customer_id INT, FOREIGN KEY(customer_id) REFERENCES customers(id))');
        $pdo->exec('INSERT INTO orders VALUES (10,1)');
        $db->relationship(MySqlComplexOrder::class, 'Customer', 'customers', ['customer_id'], MySqlComplexCustomer::class);
        $order = $db->set(MySqlComplexOrder::class)->asNoTracking()->include('Customer')->first();
        self::assertSame('Hong Kong', $order->getCustomer()->address->city);
        self::assertSame([], $db->changeTracker->entries());
    }

    public function testTphStreamingUsesInheritedComplexMetadataWithoutExtraQueries(): void
    {
        [$pdo, $db] = $this->fixture();
        $pdo->exec('CREATE TABLE parties (id INT AUTO_INCREMENT PRIMARY KEY, Discriminator VARCHAR(100), address_city VARCHAR(60), address_street VARCHAR(60), address_zip VARCHAR(20))');
        $pdo->exec("INSERT INTO parties VALUES (1,'MySqlComplexMember','Tokyo','Main',NULL)");
        $member = $db->set(MySqlComplexParty::class)->asNoTracking()->toArray()[0];
        self::assertInstanceOf(MySqlComplexMember::class, $member);
        self::assertSame('Tokyo', $member->address->city);
        self::assertSame([], $db->changeTracker->entries());
    }
}
