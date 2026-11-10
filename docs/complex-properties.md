# Complex properties

A complex property is a required value object stored in several columns of its
owning entity's table. It has no separate primary key, table, relationship or
tracking identity. This initial implementation supports one level of public,
mutable scalar members.

## Configure a value object

```php
use Light\Entity\{DbContext, Entity, ModelBuilder};

final class Address
{
    public function __construct(
        public string $city,
        public string $street,
        public ?string $zip = null,
    ) {}
}

final class Customer extends Entity
{
    public Address $address;
}

class AppContext extends DbContext
{
    protected function onModelCreating(ModelBuilder $builder): void
    {
        $builder->entity(Customer::class)->complexProperty('address');
    }
}
```

The type is inferred from the declared `Address` property. For a dynamic
`Entity` property, specify it explicitly:

```php
$builder->entity(Customer::class)->complexProperty('address', Address::class);
```

Complex properties are opt-in. Ordinary entities keep their existing mapping.
The value object does not extend `Entity` and is never registered as an entity.
Do not use `include('Address')`; its members are loaded with the owner's row.

## Database columns

By default, column names are `<property>_<member>`, preserving PHP member case.
For this example, create `customers` with `address_city`, `address_street` and
`address_zip`. A SQLite schema could be:

```sql
CREATE TABLE customers (
    id INTEGER PRIMARY KEY,
    name TEXT,
    address_city TEXT NOT NULL,
    address_street TEXT NOT NULL DEFAULT 'Default street',
    address_zip TEXT
);
```

Use an `AUTO_INCREMENT` key and suitable string types for MySQL. light-entity
is database-first; configuration validates existing columns and never creates
or migrates them automatically. Complex properties do not require a TPH
discriminator unless the owning entities also form an inheritance hierarchy.

Override individual column names as needed; unspecified members keep their
default names:

```php
$builder->entity(Customer::class)->complexProperty(
    'address',
    Address::class,
    columns: ['city' => 'city_name', 'street' => 'street_line'],
);
```

Every public instance member is mapped. Static members are ignored. Columns
must exist and cannot overlap another complex property, a separately declared
scalar property, an entity primary key or a TPH discriminator. Private and
protected members are not persisted.

## Read, modify and save

```php
$customer = $db->set(Customer::class)->find(1);
echo $customer->address->city;

$customer->address->city = 'Tokyo';
$db->saveChanges(); // Updates address_city, preserving unchanged members.

$newCustomer = new Customer();
$newCustomer->name = 'Ada';
$newCustomer->address = new Address('Hong Kong', 'Main Street');
$db->set(Customer::class)->add($newCustomer);
$db->saveChanges();
```

Hydration bypasses constructors, converts database values using the existing
schema/converter rules, then assembles the value object. Required constructors
are therefore supported, but constructor side effects and validation are not
executed during reads. Members and the owning property must be public and
mutable. Uninitialized members are omitted from inserts so database defaults
and computed values can populate them. Save reloads those values into the same
complex instance. Computed columns retain their existing write protection.

Tracking snapshots contain independent scalar leaf values. Both mutation of
an existing member and replacement of the whole object are detected. Replacing
an object with equal member values leaves the entity unchanged. Sharing one
complex instance between owners means mutating it can modify both owners.
No-tracking reads create independent value objects and do not create tracking
records for them.

The complex object itself is required; initialize it before adding or attaching
an entity. Individual scalar members may be nullable if their PHP and database
types allow null. When all members permit null, all-null columns still describe an object, not a
null complex property.

## Queries and bulk updates

```php
$customers = $db->set(Customer::class)
    ->where(fn ($c) => $c->address->city == 'Hong Kong')
    ->orderBy(fn ($c) => $c->address->street)
    ->toArray();

$cities = $db->set(Customer::class)
    ->select(fn ($c) => $c->address->city)
    ->toArray();

$db->set(Customer::class)
    ->where(['address.city' => 'Hong Kong'])
    ->executeUpdate(['address.city' => 'Tokyo']);
```

The SQL provider binds logical member paths to physical columns using each
source's metadata. Supported scalar members work in predicates, ordering,
projections, aggregates, grouping, global filters, join keys and correlated
subqueries. With explicit expressions use `Expr::field('address.city')`; join
result selectors use paths such as `outer.address.city` and `inner.address.city`.
After a projection, subsequent operators use its output aliases.

Trusted raw SQL still uses actual column names and must return every mapped
column for entity materialization. `executeUpdate()` accepts member paths in
assignment keys and expressions. It retains the existing bulk-write behavior:
it does not refresh already tracked entities or their snapshots.

## Explicit metadata and inheritance

An explicit mapping can attach complex metadata directly:

```php
$mapping = new \Light\Entity\EntityMapping(
    Customer::class,
    'customers',
    ['id', 'name', 'address_city', 'address_street', 'address_zip'],
    complexProperties: [
        'address' => new \Light\Entity\ComplexPropertyMapping('address', Address::class),
    ],
);
```

Configure supplied `EntityMapping` instances directly rather than configuring
them again in `onModelCreating()`. Any leaf converters/comparers use physical
column names. Complex members remain scalar values; object-valued leaf
converters are outside this version's scope.

TPH subtypes inherit complex configuration from their base types. An inherited
complex property cannot be remapped to a different type or column set on a
derived type. Streaming and Include preserve both the entity subtype and its
complex members; incompatible native single-class hydration shortcuts are
bypassed.

`EntityEntry::currentValues` and `originalValues` continue to expose flat
physical-column dictionaries, e.g. `address_city`. Use the value object's
members for application reads and writes; assigning a physical leaf name
on the entity is rejected during value extraction instead of changing the object.

## Initial scope

This is a subset of EF Core's complex-property feature. It does not implement:

- Nullable complex objects, nested complex objects, collections or JSON storage.
- Readonly value objects, property hooks, constructor binding or custom factories.
- Whole-object SQL equality or whole-object projections; select individual
  scalar members, or use `asEnumerable()` for PHP object operations.
- A separate `ComplexPropertyEntry` API; snapshots are exposed as flat values.

Unknown member paths, invalid mappings and unsupported object shapes are
rejected explicitly. Applications without configured complex properties do not
need any schema changes for this feature.
