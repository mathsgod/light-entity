<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, Entity, EntityMapping, EntityState, ModelBuilder, Table, ComplexPropertyMapping};
use Light\Linq\Expr;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class ComplexAddress
{
    public function __construct(public string $city, public string $street, public ?string $zip = null) {}
}
#[Table('cp_customers')]
final class ComplexCustomer extends Entity { public ComplexAddress $address; }
#[Table('cp_customers')]
final class ComplexDynamicCustomer extends Entity {}
#[Table('cp_orders')]
final class ComplexOrder extends Entity {}
#[Table('cp_parties')]
class ComplexParty extends Entity { public ComplexAddress $address; }
final class ComplexMember extends ComplexParty {}
class NestedComplexAddress { public ComplexAddress $nested; }
class OptionalComplexOwner extends Entity { public ?ComplexAddress $address; }
class ReadonlyComplexAddress { public readonly string $city; }
class TwoComplexCustomer extends Entity { public ComplexAddress $billing; public ComplexAddress $shipping; }

class ComplexPoint { public int $x; public int $y; public int $total; }
final class ComplexLocation extends Entity { public ComplexPoint $point; }

final class ComplexReadPDO extends PDO
{
    public array $writes = [];
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_starts_with($query, 'UPDATE')) $this->writes[] = $query;
        return parent::prepare($query, $options);
    }
}
class ComplexContext extends DbContext
{
    protected function onModelCreating(ModelBuilder $builder): void
    {
        $builder->entity(ComplexCustomer::class)->complexProperty('address');
    }
}

final class ComplexPropertyTest extends TestCase
{
    private ComplexReadPDO $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite required.');
        $this->pdo = new ComplexReadPDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE cp_customers (id INTEGER PRIMARY KEY, name TEXT, address_city TEXT NOT NULL, address_street TEXT NOT NULL DEFAULT 'Default street', address_zip TEXT)");
        $this->pdo->exec("INSERT INTO cp_customers VALUES (1,'Ada','Hong Kong','First',NULL),(2,'Bob','London','Second','SW1'),(3,'Cat','Hong Kong','Third','HK')");
        $this->db = new ComplexContext($this->pdo);
    }

    public function testHydratesARequiredValueObjectWithoutASeparateTableOrIdentity(): void
    {
        $customer = $this->db->set(ComplexCustomer::class)->find(1);
        self::assertInstanceOf(ComplexAddress::class, $customer->address);
        self::assertSame('Hong Kong', $customer->address->city);
        self::assertSame('First', $customer->address->street);
        self::assertNull($customer->address->zip);
        self::assertCount(1, $this->db->changeTracker->entries());
        self::assertSame('Hong Kong', $this->db->entry($customer)->originalValues['address_city']);
        self::assertArrayNotHasKey('address_city', $customer->storedValues());
        self::assertSame($customer, $this->db->set(ComplexCustomer::class)->find(1));
    }

    public function testDetectsMemberMutationAndUpdatesOnlyChangedLeafColumns(): void
    {
        $customer = $this->db->set(ComplexCustomer::class)->find(1);
        $address = $customer->address;
        $customer->address->city = 'Tokyo';
        self::assertSame(EntityState::Modified, $this->db->entry($customer)->state);
        self::assertSame('Hong Kong', $this->db->entry($customer)->originalValues['address_city']);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame($address, $customer->address);
        self::assertStringContainsString('"address_city" = ?', $this->pdo->writes[0]);
        self::assertStringNotContainsString('address_street', $this->pdo->writes[0]);
        self::assertSame('Tokyo', $this->pdo->query('SELECT address_city FROM cp_customers WHERE id=1')->fetchColumn());
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testObjectReplacementUsesValueSnapshots(): void
    {
        $customer = $this->db->set(ComplexCustomer::class)->find(1);
        $customer->address = new ComplexAddress('Hong Kong', 'First');
        self::assertSame(EntityState::Unchanged, $this->db->entry($customer)->state);
        $customer->address = new ComplexAddress('Tokyo', 'Fourth', '123');
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('Fourth', $this->pdo->query('SELECT address_street FROM cp_customers WHERE id=1')->fetchColumn());
    }

    public function testInsertAndReloadPopulateComplexMembers(): void
    {
        $customer = new ComplexCustomer();
        $customer->name = 'New';
        $customer->address = new ComplexAddress('Paris', 'Rue', '75000');
        $this->db->set(ComplexCustomer::class)->add($customer);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('Paris', $this->pdo->query('SELECT address_city FROM cp_customers WHERE id='.$customer->id)->fetchColumn());
        self::assertSame('75000', $customer->address->zip);
        $this->db->set(ComplexCustomer::class)->remove($customer);
        self::assertSame(1, $this->db->saveChanges());
        self::assertNull($this->db->set(ComplexCustomer::class)->find($customer->id));
    }

    public function testUninitializedLeafUsesDatabaseDefault(): void
    {
        $customer = new ComplexCustomer();
        $customer->address = (new \ReflectionClass(ComplexAddress::class))->newInstanceWithoutConstructor();
        $customer->address->city = 'Paris';
        $this->db->set(ComplexCustomer::class)->add($customer);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('Default street', $customer->address->street);
    }

    public function testFiltersOrderingAndProjectionTranslateMemberPaths(): void
    {
        $set = $this->db->set(ComplexCustomer::class);
        self::assertSame([3, 1], $set->where(fn ($c) => $c->address->city == 'Hong Kong')
            ->orderByDescending(fn ($c) => $c->address->street)
            ->select(fn ($c) => $c->id)->toArray());
        self::assertSame(['Hong Kong', 'London', 'Hong Kong'], $set->orderBy(Expr::field('id'))->select(fn ($c) => $c->address->city)->toArray());
        self::assertSame(2, $set->where(['address.city' => 'Hong Kong'])->count());
        self::assertSame('Hong Kong', $set->min(fn ($c) => $c->address->city));
        self::assertSame('London', $set->max(fn ($c) => $c->address->city));
        self::assertSame([], $this->db->changeTracker->entries());
        $projected = $set->select(Expr::projection(['address' => 'address.city']))->where(Expr::eq(Expr::field('address'), 'London'))->toArray();
        self::assertSame([['address' => 'London']], $projected);
    }

    public function testTerminalPredicatesAndScalarFunctionsUseMemberColumns(): void
    {
        $set = $this->db->set(ComplexCustomer::class);
        self::assertSame(2, $set->single(fn ($c) => $c->address->city == 'London')->id);
        self::assertTrue($set->all(fn ($c) => strlen($c->address->city) > 0));
        self::assertSame(2, $set->where(fn ($c) => str_contains($c->address->street, 'ir'))->count());
        self::assertSame(['Hong Kong' => 2, 'London' => 1], array_column($set->groupBy(fn ($c) => $c->address->city)
            ->select(Expr::projection(['city' => Expr::key(), 'n' => Expr::count()]))->toArray(), 'n', 'city'));
    }

    public function testCorrelatedAnyAndJoinKeysKeepSourceScopes(): void
    {
        $set = $this->db->set(ComplexCustomer::class);
        self::assertSame([1, 3], $set->where(fn ($c) => $set->any(fn ($other) => $other->address->city == $c->address->city && $other->id != $c->id))
            ->orderBy(Expr::field('id'))->select(Expr::field('id'))->toArray());
        $join = $set->join($set,
            fn ($c) => $c->address->city,
            fn ($c) => $c->address->city,
            Expr::projection(['city' => 'outer.address.city', 'other' => 'inner.address.city']));
        self::assertCount(5, $join->toArray());
        self::assertSame(['city' => 'Hong Kong', 'other' => 'Hong Kong'], $join->toArray()[0]);
    }

    public function testContainsSubqueriesAndWhereInUseMemberPaths(): void
    {
        $set = $this->db->set(ComplexCustomer::class);
        $cities = $set->where(['id' => 2])->select(fn ($c) => $c->address->city);
        self::assertSame(1, $set->where(fn ($c) => $cities->contains($c->address->city))->count());
        self::assertSame(1, $set->whereIn('address.city', $cities)->count());
    }

    public function testBulkAssignmentsAndRawSqlUsePhysicalColumns(): void
    {
        $set = $this->db->set(ComplexCustomer::class);
        self::assertSame(2, $set->where(['address.city' => 'Hong Kong'])->executeUpdate(['address.city' => 'Tokyo']));
        self::assertSame('Tokyo', $set->fromSqlRaw('SELECT * FROM cp_customers WHERE id = ?', [1])->first()->address->city);
        self::assertSame(1, $set->where(['id' => 2])->executeUpdate(['address.street' => Expr::field('address.city')]));
        self::assertSame('London', $set->find(2)->address->street);
    }

    public function testNoTrackingAndStreamingCreateIndependentValueObjects(): void
    {
        $set = $this->db->set(ComplexCustomer::class)->asNoTracking();
        $first = $set->find(1);
        $again = $set->find(1);
        self::assertNotSame($first->address, $again->address);
        $first->address->city = 'Tokyo';
        self::assertSame('Hong Kong', $again->address->city);
        self::assertSame([], $this->db->changeTracker->entries());
        self::assertSame(0, $this->db->saveChanges());
        self::assertCount(3, iterator_to_array($set));
    }

    public function testSharedObjectsAreTrackedByEachOwnersLeafValues(): void
    {
        $first = $this->db->set(ComplexCustomer::class)->find(1);
        $second = $this->db->set(ComplexCustomer::class)->find(2);
        $second->address = $first->address;
        $first->address->city = 'Tokyo';
        self::assertSame(2, $this->db->saveChanges());
        self::assertSame('Tokyo', $this->pdo->query('SELECT address_city FROM cp_customers WHERE id=2')->fetchColumn());
    }

    public function testExplicitTypeSupportsDynamicEntityAndColumnOverrides(): void
    {
        $db = new class($this->pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                $builder->entity(ComplexDynamicCustomer::class)->complexProperty('contact', ComplexAddress::class,
                    ['city' => 'address_city', 'street' => 'address_street', 'zip' => 'address_zip']);
            }
        };
        self::assertSame('London', $db->set(ComplexDynamicCustomer::class)->find(2)->contact->city);
        self::assertSame(2, $db->set(ComplexDynamicCustomer::class)->where(fn ($c) => $c->contact->city == 'Hong Kong')->count());
    }

    public function testGlobalFiltersUseComplexMemberPaths(): void
    {
        $db = new class($this->pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                $builder->entity(ComplexCustomer::class)->complexProperty('address')->hasQueryFilter('city', ['address.city' => 'London']);
            }
        };
        self::assertSame(1, $db->set(ComplexCustomer::class)->count());
        self::assertSame(3, $db->set(ComplexCustomer::class)->ignoreQueryFilters()->count());
    }

    public function testWholeComplexProjectionFailsExplicitly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('whole complex objects');
        $this->db->set(ComplexCustomer::class)->select(fn ($c) => $c->address)->toArray();
    }

    public function testUnknownComplexMemberFailsExplicitly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unknown or nested complex member');
        $this->db->set(ComplexCustomer::class)->orderBy(Expr::field('address.unknown'))->toArray();
    }

    public function testMissingRequiredObjectFailsBeforeInsert(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an instance');
        $this->db->set(ComplexCustomer::class)->add(new ComplexCustomer());
    }

    public function testIncludeHydratesComplexMembersWithoutTrackingTheValueObject(): void
    {
        $this->pdo->exec('CREATE TABLE cp_orders (id INTEGER PRIMARY KEY, customer_id INTEGER REFERENCES cp_customers(id)); INSERT INTO cp_orders VALUES (10, 1)');
        $this->db->relationship(ComplexOrder::class, 'Customer', 'cp_customers', ['customer_id'], ComplexCustomer::class);
        $order = $this->db->set(ComplexOrder::class)->include('Customer')->first();
        self::assertSame('Hong Kong', $order->getCustomer()->address->city);
        self::assertSame(2, count($this->db->changeTracker->entries()));
        $order->getCustomer()->address->city = 'Tokyo';
        self::assertSame(1, $this->db->saveChanges());
        $order = $this->db->set(ComplexOrder::class)->asNoTracking()->include('Customer')->first();
        self::assertSame('Tokyo', $order->getCustomer()->address->city);
    }

    public function testTphInheritsComplexMappingAndStreamsConcreteTypes(): void
    {
        $this->pdo->exec("CREATE TABLE cp_parties (id INTEGER PRIMARY KEY, Discriminator TEXT, address_city TEXT, address_street TEXT, address_zip TEXT); INSERT INTO cp_parties VALUES (1, 'ComplexMember', 'Tokyo', 'Main', NULL)");
        $db = new class($this->pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                $builder->entity(ComplexParty::class)->complexProperty('address');
            }
        };
        $member = $db->set(ComplexParty::class)->first();
        self::assertInstanceOf(ComplexMember::class, $member);
        self::assertSame('Tokyo', $member->address->city);
        self::assertSame($member, $db->set(ComplexMember::class)->first());
        $member->address->city = 'Osaka';
        self::assertSame(1, $db->saveChanges());
        self::assertSame(1, $db->set(ComplexMember::class)->where(fn ($p) => $p->address->city == 'Osaka')->count());
        $read = $db->set(ComplexParty::class)->asNoTracking()->toArray()[0];
        self::assertInstanceOf(ComplexMember::class, $read);
        self::assertSame('Osaka', $read->address->city);
    }

    public function testJoinsBindDifferentLogicalMemberPathsPerSource(): void
    {
        $db = new class($this->pdo) extends ComplexContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                parent::onModelCreating($builder);
                $builder->entity(ComplexDynamicCustomer::class)->complexProperty('contact', ComplexAddress::class,
                    ['city' => 'address_city', 'street' => 'address_street', 'zip' => 'address_zip']);
            }
        };
        $outer = $db->set(ComplexCustomer::class);
        $inner = $db->set(ComplexDynamicCustomer::class);
        self::assertCount(5, $outer->join($inner,
            fn ($c) => $c->address->city,
            fn ($c) => $c->contact->city,
            Expr::projection(['a' => 'outer.address.city', 'b' => 'inner.contact.city']))->toArray());
    }

    public function testTwoPropertiesOfSameComplexTypeMapToSeparateColumns(): void
    {
        $this->pdo->exec('CREATE TABLE two_complex_customers (id INTEGER PRIMARY KEY, billing_city TEXT, billing_street TEXT, billing_zip TEXT, shipping_city TEXT, shipping_street TEXT, shipping_zip TEXT)');
        $db = new class($this->pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                $builder->entity(TwoComplexCustomer::class)->complexProperty('billing')->complexProperty('shipping');
            }
        };
        $customer = new TwoComplexCustomer();
        $customer->billing = new ComplexAddress('London', 'A');
        $customer->shipping = new ComplexAddress('Tokyo', 'B');
        $db->set(TwoComplexCustomer::class)->add($customer);
        self::assertSame(1, $db->saveChanges());
        $fresh = $db->set(TwoComplexCustomer::class)->asNoTracking()->first();
        self::assertSame('London', $fresh->billing->city);
        self::assertSame('Tokyo', $fresh->shipping->city);
        self::assertSame(1, $db->set(TwoComplexCustomer::class)->where(fn ($c) => $c->billing->city != $c->shipping->city)->count());
    }

    public function testExplicitMappingsCanConfigureComplexProperties(): void
    {
        $mapping = new EntityMapping(ComplexCustomer::class, 'cp_customers', ['id', 'name', 'address_city', 'address_street', 'address_zip'],
            complexProperties: ['address' => new ComplexPropertyMapping('address', ComplexAddress::class)]);
        $db = new DbContext($this->pdo, mappings: [$mapping]);
        self::assertSame('London', $db->set(ComplexCustomer::class)->find(2)->address->city);
        self::assertSame(2, $db->set(ComplexCustomer::class)->where(['address.city' => 'Hong Kong'])->count());
    }

    public function testNullableObjectsAreRejectedAtConfiguration(): void
    {
        $builder = new ModelBuilder();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('required objects');
        $builder->entity(OptionalComplexOwner::class)->complexProperty('address');
    }

    public function testNestedComplexTypesAreRejectedAtConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nested objects');
        new ComplexPropertyMapping('address', NestedComplexAddress::class);
    }

    public function testReadonlyComplexMembersAreRejectedAtConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mutable scalar');
        new ComplexPropertyMapping('address', ReadonlyComplexAddress::class);
    }

    public function testComplexMembersCannotMapToPrimaryKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('protected complex member column');
        new EntityMapping(ComplexCustomer::class, 'cp_customers', ['id', 'address_street', 'address_zip'],
            complexProperties: ['address' => new ComplexPropertyMapping('address', ComplexAddress::class, ['city' => 'id'])]);
    }

    public function testMissingLeafColumnsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('complex member column');
        new EntityMapping(ComplexCustomer::class, 'cp_customers', ['id', 'address_city'],
            complexProperties: ['address' => new ComplexPropertyMapping('address', ComplexAddress::class)]);
    }

    public function testDiscriminatorAndComplexColumnsCannotOverlap(): void
    {
        $this->pdo->exec('CREATE TABLE cp_parties (id INTEGER PRIMARY KEY, Discriminator TEXT, address_street TEXT, address_zip TEXT)');
        $db = new class($this->pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                $builder->entity(ComplexParty::class)->complexProperty('address', columns: ['city' => 'Discriminator']);
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('protected complex member column');
        $db->set(ComplexParty::class);
    }

    public function testComputedMembersAndDefaultsReloadIntoTheObject(): void
    {
        $this->pdo->exec('CREATE TABLE complex_locations (id INTEGER PRIMARY KEY, point_x INT NOT NULL, point_y INT DEFAULT 2, point_total INT GENERATED ALWAYS AS (point_x + point_y) STORED)');
        $db = new class($this->pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                $builder->entity(ComplexLocation::class)->complexProperty('point');
            }
        };
        $location = new ComplexLocation();
        $location->point = new ComplexPoint();
        $location->point->x = 3;
        $db->set(ComplexLocation::class)->add($location);
        self::assertSame(1, $db->saveChanges());
        self::assertSame(2, $location->point->y);
        self::assertSame(5, $location->point->total);
        $location->point->x = 4;
        self::assertSame(1, $db->saveChanges());
        self::assertSame(6, $location->point->total);
        $location->point->total = 100;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Computed columns');
        $db->saveChanges();
    }

    public function testLeafConvertersAreAppliedOnceToLogicalMemberFiltersAndBulkValues(): void
    {
        $this->pdo->exec("UPDATE cp_customers SET address_city = '@' || LOWER(address_city)");
        $mapping = new EntityMapping(ComplexCustomer::class, 'cp_customers', ['id','name','address_city','address_street','address_zip'],
            converters: ['address_city' => new \Light\Entity\ValueConverter(fn ($value) => '@'.$value, fn ($value) => substr($value, 1))],
            complexProperties: ['address' => new ComplexPropertyMapping('address', ComplexAddress::class)]);
        $db = new DbContext($this->pdo, mappings: [$mapping]);
        self::assertSame('london', $db->set(ComplexCustomer::class)->find(2)->address->city);
        self::assertSame(2, $db->set(ComplexCustomer::class)->where(fn ($c) => $c->address->city == 'hong kong')->count());
        self::assertSame(2, $db->set(ComplexCustomer::class)->where(['address.city' => 'hong kong'])->count());
        self::assertSame(2, $db->set(ComplexCustomer::class)->where(['address.city' => 'hong kong'])->executeUpdate(['address.city' => 'tokyo']));
        self::assertSame('@tokyo', $this->pdo->query('SELECT address_city FROM cp_customers WHERE id=1')->fetchColumn());
    }

    public function testInheritedComplexPropertyCannotBeRemappedInATphSubtype(): void
    {
        $db = new class($this->pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                $builder->entity(ComplexParty::class)->complexProperty('address');
                $builder->entity(ComplexMember::class)->complexProperty('address', columns: ['city' => 'other_city']);
            }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be remapped');
        $db->set(ComplexParty::class);
    }

    public function testAllDialectsCompileBoundPhysicalMemberColumns(): void
    {
        foreach ([new \Light\Entity\Sql\SqliteDialect(), new \Light\Entity\Sql\MySqlDialect(),
            new \Light\Entity\Sql\PostgreSqlDialect(), new \Light\Entity\Sql\SqlServerDialect()] as $dialect) {
            $db = new ComplexContext($this->pdo, $dialect);
            $query = $db->set(ComplexCustomer::class)->where(fn ($c) => $c->address->city == 'Hong Kong')
                ->orderBy(fn ($c) => $c->address->street)
                ->select(fn ($c) => $c->address->city);
            $compiled = $query->provider()->compile($query->expression());
            self::assertStringContainsString($dialect->quoteIdentifier('address_city'), $compiled->sql);
            self::assertStringContainsString($dialect->quoteIdentifier('address_street'), $compiled->sql);
            self::assertStringNotContainsString($dialect->quoteIdentifier('address.city'), $compiled->sql);
            self::assertSame(['p0' => 'Hong Kong'], $compiled->parameters);
        }
    }

    public function testPhysicalLeafAssignmentsOnTheEntityAreRejected(): void
    {
        $customer = $this->db->set(ComplexCustomer::class)->find(1);
        $customer->address_city = 'Tokyo';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Assign complex object members');
        $this->db->saveChanges();
    }

    public function testConditionalCorrelationRetainsMetadataAndComplexMemberBinding(): void
    {
        $set = $this->db->set(ComplexCustomer::class);
        self::assertSame(2, $set->where(fn ($c) =>
            ($set->any(fn ($o) => $o->address->city == $c->address->city && $o->id != $c->id)
                ? $c->address->city : '') == 'Hong Kong')->count());
    }

    public function testNullColumnOverridesAreRejectedInsteadOfUsingTheDefault(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('column names');
        new ComplexPropertyMapping('address', ComplexAddress::class, ['city' => null]);
    }

    public function testRawEntityReadsRequireAllComplexLeafColumns(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('missing mapped column: address_street');
        $this->db->set(ComplexCustomer::class)->fromSqlRaw('SELECT id, name, address_city FROM cp_customers')->first();
    }
}
