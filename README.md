# light-entity

A PHP 8.3+ database-first ORM built on PDO and `mathsgod/light-linq`, inspired by
Microsoft Entity Framework Core's DbContext, DbSet and unit-of-work patterns.
This is a PHP implementation with its own
API and supported feature set, not an EF Core API compatibility layer.

## Complex properties

Use a complex property to store a value object in its owner's table without a
separate entity, primary key or relationship. Configure it explicitly in
`onModelCreating()`:

```php
use Light\Entity\{DbContext, Entity, ModelBuilder};

final class Address
{
    public function __construct(public string $city, public string $street) {}
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

The object type is inferred from the declared property. The default columns are
`address_city` and `address_street`; create them in the `customers` table yourself.
Configuration does not create or migrate the schema. For a dynamic entity
property, pass the type explicitly with
`complexProperty('address', Address::class)`. Custom column names are also
supported:

```php
$builder->entity(Customer::class)->complexProperty(
    'address',
    Address::class,
    columns: ['city' => 'city_name', 'street' => 'street_line'],
);
```

Queries use the object's member paths. Filtering and ordering execute in SQL,
and the returned Customer objects contain their Address objects without Include:

```php
$customers = $db->set(Customer::class)
    ->where(fn ($c) => $c->address->city == 'Hong Kong')
    ->orderBy(fn ($c) => $c->address->street)
    ->toArray();

$customer = $db->set(Customer::class)->find(1);
$customer->address->city = 'Tokyo';
$db->saveChanges(); // Updates only the changed leaf columns.
```

Bulk filters and assignments accept logical member paths. With the default
mapping, the physical column name is accepted too:

```php
$db->set(Customer::class)
    ->where(['address.city' => 'Hong Kong'])
    ->executeUpdate(['address.city' => 'Tokyo']);

// Equivalent assignment with the default column mapping:
$db->set(Customer::class)
    ->where(['address.city' => 'Hong Kong'])
    ->executeUpdate(['address_city' => 'Tokyo']);
```

Prefer `address.city` so application code survives changes to the configured
column name. On entity objects, modify `$customer->address->city`; assigning
`$customer->address_city` is rejected during value extraction. Bulk updates do
not refresh already tracked objects or their snapshots.

The initial implementation supports required, single-level objects with public
mutable scalar members, including nullable scalar members. Nullable complex
objects, nested objects, readonly members, collections, JSON storage and
whole-object SQL comparisons/projections are not supported. See
[Complex properties](docs/complex-properties.md) for full configuration and
tracking details.

## Entity inheritance

Inherited `Entity` classes now use table-per-hierarchy (TPH) by default: `Cat`
and `Dog` share the root `Animal` table, with a string `Discriminator` column.
Derived queries filter by type; base queries materialize the corresponding PHP
subclasses. See [Inheritance](docs/inheritance.md) for schema, configuration and
migration requirements. This is an unreleased breaking convention change.

## Dependency compatibility

The current checkout requires stable `mathsgod/light-linq:^1.3` (installed v1.3.0).
See [LINQ 1.3 implementation](docs/linq-1.3-implementation.md) for operator support and examples.
DbSet implements the expanded IQueryable interface and accepts closure selectors
and terminal predicates. New LINQ operators are exposed, but SQL translation is
still limited to this provider's supported operations; use `asEnumerable()` for
explicit PHP execution. DbSet `elementAt()`/`elementAtOrDefault()` enumerate the
existing entity query and preserve its pagination, materialization and tracking.

## v1.2.0

Originally required stable `mathsgod/light-linq:^1.2`. Adds arithmetic, string matching,
scalar functions, coalesce and conditional query expressions. Also includes
bound raw ordering and cross-context IN subqueries over a shared connection.
See [CHANGELOG](CHANGELOG.md) and [Closure translation](docs/closure-translation.md).

## 1.0 support scope and compatibility

The following defines the support contract for the 1.0 release and subsequent
1.x versions; it does not indicate that a release has already been published.

| Component | 1.0 support commitment |
| --- | --- |
| Runtime | PHP 8.3 or later, PDO, and the corresponding PDO database driver |
| MySQL | MySQL 8.0 is the integration-test baseline for schema discovery, queries, tracking, persistence, relationships and supported DDL |
| SQLite | SQLite 3.35+ with `pdo_sqlite` for schema discovery, queries, tracking, persistence, relationships and supported native DDL |
| MariaDB | MySQL-driver compatibility is implemented, but there is no dedicated MariaDB integration suite; it is outside the guaranteed 1.0 support baseline |
| PostgreSQL / SQL Server | SQL dialects cover documented query translation; full ORM/schema discovery and DDL support are outside the 1.0 support baseline |

Database support is limited to the operations documented here. For example,
SQLite foreign-key changes that require rebuilding tables are rejected. A SQL
dialect's presence does not imply end-to-end ORM support for that database.

The supported API includes documented entity mapping, DbContext/DbSet queries,
identity and snapshot tracking, `saveChanges()`, DatabaseFacade transactions,
FK relationship getters and Include, converters/comparers, entity entries,
save interceptors, global query filters, raw SQL, bulk writes and MigrationBuilder
operations. Use a short-lived context for each unit of work.

### Compatibility policy for 1.x

- Documented public APIs and behavior form the compatibility contract. Breaking
  changes to those APIs, mapping conventions or supported semantics require a
  new major version.
- Minor releases may add APIs, query operators and database capabilities while
  preserving existing supported behavior. Patch releases provide compatible
  fixes. Incorrect behavior may be corrected to match the documented contract.
- Private/protected implementation details, APIs marked `@internal`, generated
  SQL formatting, query counts and benchmark results are not compatibility
  guarantees. Documented extension hooks such as `onModelCreating()` remain part
  of the public contract.
- Exceptions documented for unsupported operations are intentional boundaries;
  application code should not depend on incidental support outside this scope.

### Deliberate limitations in 1.0

- No automatic optimistic-concurrency tokens or conflict detection: tracked
  updates/deletes match primary keys. Global query filters do not add predicates
  to `saveChanges()` writes and are not database access controls.
- No migration version/history store, automatic schema diff, or managed Up/Down
  runner. MigrationBuilder generates and executes explicit operations; MySQL DDL
  can implicitly commit and is not made atomic by the ORM.
- No custom entity property-to-column aliases, primary-key converters/comparers,
  or asynchronous database I/O.
- Relationship loading uses database FK metadata and explicit getter overrides;
  it is not a promise of EF Core's full relationship persistence semantics.
- A successful `saveChanges()` inside a facade transaction accepts PHP snapshots
  before the outer commit. A full outer rollback does not rewind entities or
  tracking state: discard that context afterwards.
- Raw SQL and immediate bulk writes do not refresh tracked objects. Schema
  changes require a new context to refresh cached metadata.

See the feature sections below for query, relationship, streaming and DDL
restrictions. These boundaries do not prevent adding compatible capabilities
in later 1.x releases.

## Runtime automatic mapping

The first `set()` or `model->getEntityType()` for an entity reads the database schema to discover columns, database types, nullability, primary key, generated identity, defaults and computed columns. Metadata is cached per context. No scaffolding command or property list is required.

```php
use Light\Entity\DbContext;
use Light\Entity\Entity;

// Defaults to the table "users". No manually declared properties required.
final class User extends Entity {}

$pdo = new PDO('mysql:host=localhost;dbname=app;charset=utf8mb4', 'username', 'password');
$db = new DbContext($pdo);
$users = $db->set(User::class);

$user = $users->where(['id' => 1])->first();
$user->name = 'Peter';
$db->saveChanges();

$new = new User();
$new->name = 'Mary';
$users->add($new);
$db->saveChanges(); // Generated ID, defaults and computed values are loaded back.

$users->remove($new);
$db->saveChanges();
```

Entity queries require existing tables. The optional MigrationBuilder can create tables before querying them (see below). Dialects are selected automatically for PDO MySQL/MariaDB and SQLite.

## Table names and typed properties

The default naming convention is snake_case class name plus `s` (`User` → `users`, `OrderItem` → `order_items`). Irregular plurals are not inferred. Override with an attribute:

```php
use Light\Entity\Table;

#[Table('people')]
final class Person extends Entity {}
```

Or call `$db->set(Person::class, 'people')`. One class maps to one table per context. Qualified `schema.table` names are supported.

Public typed properties can be declared instead of using `Entity`, but every database column must have a compatible public mutable property. An `Entity` subclass can mix declared properties with schema-driven properties. Runtime discovery does not generate PHP declarations or IDE autocomplete.

```php
final class User
{
    public ?int $id = null;
    public string $name; // Leave uninitialized to let the database apply its default.
}
```

Unknown dynamic properties are not persisted. Only discovered columns participate in mapping. Schema changes are discovered by a new context, not an already-cached mapping.

## Types and defaults

- Integer columns become PHP integers when within PHP's integer range; larger values remain strings.
- Boolean declarations become booleans when the database reports a boolean type. MySQL `TINYINT(1)` remains an integer, preserving values such as 0, 1 and 2; NULL stays null. MySQL reports `BOOLEAN`/`BOOL` aliases as `TINYINT(1)`, so these also remain integers. Configure a `ValueConverter` explicitly when an application wants boolean semantics.
- Floating-point columns become floats. Decimal/numeric, dates, JSON and binary values remain strings; JSON is not decoded automatically. SQLite's own numeric storage may already lose decimal precision.
- Nullable database values remain null. Database constraints enforce nullability and required columns.
- Unset dynamic or uninitialized declared properties are omitted from inserts, allowing database defaults. Explicit null is sent as null; it does not request the default.
- Generated keys and computed columns are omitted from inserts. Attempts to modify computed columns are rejected. Values are reloaded after inserts/updates to include defaults and generated values.

## Context behavior

`Entity` implements `JsonSerializable`, so `json_encode($entities, JSON_THROW_ON_ERROR)`
exports the internal field values together with initialized public properties.
Public properties override same-named internal values. Uninitialized typed
properties, static properties, protected/private members, and relation context
are excluded. Custom initialized public properties are included even when they
are not mapped database columns. JSON database columns remain strings as above.
The initialized values are read per instance, while the public-property list is
cached per entity class. Ordinary entities with no public properties return their
internal data directly. Classes with custom setters or `AllowDynamicProperties`
retain a per-instance scan so dynamically created public properties are included.

`DbSet` supports `include`, `where`, `orderBy`, `orderByDescending`, `skip`, `take`, `first`, `find`, `any`, `count`, `toArray`, and iteration. `first()` throws `UnderflowException` when empty. Root `find()` checks the identity map before querying and returns null when missing.

Entities are tracked by identity and snapshots. Re-querying a tracked key returns the same object without refreshing pending changes. `add`, `addRange` and `remove` are deferred; removing a pending insert cancels it.

`saveChanges()` uses its own transaction unless a facade transaction is active, and returns saved entity write operations, not database affected-row counts. Transactions opened directly on PDO are rejected by `saveChanges()`; use `$db->database->beginTransaction()` to share a transaction. Failed writes retain pending changes for retry without assigning generated IDs or database defaults to entities. Changing a tracked primary key is rejected. Constructors are bypassed during hydration.

Explicit `EntityMapping` objects remain an optional override for existing callers. Automatic schema discovery requires at least one primary-key column and supports composite keys. SQLite uses rowid-backed INTEGER primary keys for generated IDs; MySQL/MariaDB use AUTO_INCREMENT. Generated IDs use PDO `lastInsertId()`.

## Composite primary keys

Existing tables with composite primary keys are discovered automatically. Use a
named key array for `find()`; component order does not matter:

```php
$items = $db->set(OrderItem::class, 'order_items');
$item = $items->find(['order_id' => 100, 'product_id' => 20]);
$item->quantity = 3;
$db->saveChanges(); // WHERE order_id = ? AND product_id = ?
```

Single-key `find(123)` remains supported. All key components must be provided
and non-null. Tracking compares the full tuple, and tracked key components
cannot be changed. Updates, deletes and reloads use every key component.

For explicit mappings, pass the key column list and disable generated keys when
all components are manually assigned:

```php
$mapping = new EntityMapping(
    OrderItem::class, 'order_items', ['order_id', 'product_id', 'quantity'],
    key: ['order_id', 'product_id'], generatedKey: false,
);
```

For a composite key with one auto-increment component, automatic discovery
identifies that column. Explicit mappings with `generatedKey: true` must mark
exactly one key column as `generated` in their `columns` metadata. The generated
value is combined with the other components to reload the inserted row, and is
assigned to the entity only after a successful save.

## Setup and tests

The test suite uses PHPUnit 12.5 and requires PHP 8.3 or later.

GitHub Actions runs the ordinary SQLite suite and the MySQL 8 integration suite
on PHP 8.3, 8.4 and 8.5 for pushes and pull requests. The workflow can also be
started manually. CI installs the required PDO drivers and fails on skipped
tests. PHP 8.3 explicitly excludes the one serialization test that requires
PHP 8.4 property hooks; PHP 8.4 and 8.5 run it. See
[the test workflow](.github/workflows/tests.yml).

Run `composer install` to install dependencies, including `mathsgod/light-linq` 1.x.

Run `composer test` or `vendor/bin/phpunit` after `composer install`. The ordinary
suite combines database-independent tests with SQLite-backed tests covering
runtime discovery, types, defaults, computed columns, identity tracking,
persistence, transactions, relationships and rollback/retry. SQLite-backed tests
are skipped if `pdo_sqlite` is unavailable; a successful command with skipped
tests is not a full-suite validation.

Run `composer test:mysql` for the separate Docker MySQL 8 integration suite.
It covers queries/joins, schema discovery, tracking, persistence, transactions,
relationships/Include, global query filters, converters, raw SQL, bulk writes and
migrations. Both suites are required to validate the 1.0 database support scope;
the MySQL suite does not execute every SQLite test against MySQL. See
[PHPUnit with Docker MySQL 8](#phpunit-with-docker-mysql-8) for setup and isolation.

## Adding multiple entities

```php
$peter = new User();
$peter->name = 'Peter';
$mary = new User();
$mary->name = 'Mary';

$users->addRange([$peter, $mary]);
$db->saveChanges();
```

`addRange(iterable $entities)` accepts arrays, generators and other iterables. It calls `add()` for each entity and does not write to the database until `saveChanges()`. Empty collections do nothing. Validation follows `add()`; if an item fails, previously processed items remain tracked. It does not generate a bulk INSERT statement; writes use the existing transactional save path.

## Direct SQL query provider

`Light\Entity\SqlQueryProvider` implements `Light\Linq\IQueryProvider` and executes light-linq expression trees through PDO.
It returns rows/scalars directly; use `DbSet` for tracked entity objects.
SQL compilation and dialects live in this package under `Light\Entity\Sql`; light-linq supplies the database-independent query operators and expression trees.

The available dialects are `MySqlDialect`, `SqlServerDialect`,
`PostgreSqlDialect`, and `SqliteDialect`. They handle identifier quoting and
pagination syntax and emit query values as bound parameters. Dialect availability
does not imply automatic schema discovery support for every database.

SQL compiler and dialect classes have moved from `Light\Linq\Sql` to
`Light\Entity\Sql`; update those imports when upgrading. JOIN SQL translation also lives in this package.


```php
use Light\Entity\SqlQueryProvider;
use Light\Linq\Queryable;
use Light\Entity\Sql\MySqlDialect;

$users = Queryable::from(
    new SqlQueryProvider($pdo, new MySqlDialect()),
    'users',
);
```

The former `Light\Linq\SqlQueryProvider` class has moved to this package; update
imports to `Light\Entity\SqlQueryProvider` and add the php-entity dependency.

## DatabaseFacade

Every context exposes a readonly `$db->database` property sharing its PDO connection:

```php
$connection = $db->database->getDbConnection(); // The same PDO passed to DbContext.

$affectedRows = $db->database->executeSql(
    'UPDATE users SET name = ? WHERE id = ?',
    ['Peter', 1],
);

// Named parameters are also supported, with or without ':' in the array keys.
$db->database->executeSql(
    'UPDATE users SET name = :name WHERE id = :id',
    ['name' => 'Mary', 'id' => 1],
);
```

`executeSql()` executes immediately, binds scalar/null values, and returns the
PDO driver's affected-row count. It does not call `saveChanges()`, start a
transaction automatically, or refresh tracked entities. Use it for non-query
SQL; fetch rows through `DbSet` or the PDO connection. SQL text is supplied by
the caller; placeholders represent values, not identifiers.

### Explicit transactions

```php
$database = $db->database;
$database->beginTransaction();

try {
    $user = new User();
    $user->name = 'Peter';
    $db->set(User::class)->add($user);
    $db->saveChanges(); // Saves without committing the outer transaction.

    $database->executeSql(
        'UPDATE users SET name = ? WHERE id = ?',
        ['Mary', $user->id],
    );

    $database->commitTransaction();
} catch (\Throwable $error) {
    if ($database->inTransaction()) {
        $database->rollbackTransaction();
    }
    throw $error;
}
```

Within a facade transaction, each `saveChanges()` creates a savepoint. A failed
save rolls back to that savepoint, leaving earlier saves and SQL operations
intact for retry or explicit rollback. Savepoints currently support SQLite,
MySQL/MariaDB and PostgreSQL; other drivers reject saves inside an explicit
transaction before writing. SQLite and MySQL transaction behavior is covered by
their respective suites; MariaDB and PostgreSQL are outside the guaranteed 1.0
support baseline. Nested `beginTransaction()` is rejected.

Successful saves accept change-tracker snapshots and assign generated values
immediately, even before the outer commit. Rolling back the outer transaction
undoes database writes but does **not** rewind PHP objects or accepted tracking
state. Discard that context and use a new one after a full rollback. Raw SQL
also leaves tracked objects unchanged. This savepoint approach follows
[EF Core transaction handling](https://learn.microsoft.com/en-us/ef/core/saving/transactions).

Commit and rollback methods only manage transactions started by that facade.
Use these methods rather than transaction-control SQL or direct PDO transaction
calls. Database operations that implicitly commit (such as MySQL DDL) cannot be
made atomic by the facade; keep schema changes outside these transactions.

## Model metadata

Each context exposes a readonly `$db->model` property implementing
`Light\Entity\IModel`. `Model` owns the per-context mapping cache previously kept
inside `DbContext`; queries and writes use the same metadata instances.

```php
$db = new DbContext($pdo);

$mapping = $db->model->findEntityType(User::class); // null until loaded

// Reads the schema only if not already cached. No prior set() is needed.
$mapping = $db->model->getEntityType(User::class);
$mapping->table;
$mapping->key; // Legacy single-key name, or a composite-key array.
$mapping->keys; // Always a list of primary-key column names.
$mapping->generatedKeyProperty; // Generated key column name, or null.
$mapping->properties;
$mapping->columns['name']->type;

$loadedMappings = $db->model->getEntityTypes();
```

- `findEntityType()` only checks cached/registered mappings; it never reads the
  database and returns null when absent.
- `getEntityType()` returns cached metadata or discovers and caches it. Missing
  tables, invalid entities and unsupported schemas throw exceptions; failed
  discovery is not cached and can be retried.
- `getEntityTypes()` lists mappings already loaded or explicitly registered;
  it does not scan all tables or discover classes.

`getEntityType(User::class, 'people')` accepts the same table override as
`set()`. A class can map to only one table within a context. Metadata loaded
through either method is shared with the other. Explicit constructor mappings
are immediately available from the model without schema queries.

Use a new context after schema changes to refresh metadata. This model exposes
current entity/column mappings. FK relationship loading and explicit getter
overrides are handled by the relationship resolver; `IModel` does not expose a
unified relationship metadata API. See [FK relationship getters](#fk-relationship-getters).

## JOIN queries

`DbSet::join()` and `leftJoin()` return a `Light\Linq\Queryable` of projected
arrays or scalar values, not tracked entities. Both sets must belong to the
same context. No relationship configuration is required.

```php
$orders = $db->set(Order::class);
$customers = $db->set(Customer::class);

$rows = $orders->leftJoin(
    $customers->where(Expr::eq(Expr::field('active'), 1)),
    Expr::field('customer_id'), // Key relative to the outer source.
    Expr::field('id'),          // Key relative to the inner source.
    Expr::projection([
        'order_id' => 'outer.id',
        'customer_name' => 'inner.name',
    ]),
)->where(Expr::neq(Expr::field('customer_name'), null))
  ->orderBy(Expr::field('order_id'))
  ->take(20)
  ->toArray();
```

`join()` produces INNER JOIN; `leftJoin()` preserves unmatched outer rows and
returns null for their inner fields. Null keys do not match, including null
against null. Duplicate matches produce multiple result rows. Key selectors
may use `Expr::projection()` for composite keys with matching member names and
order; join keys are independent of entity primary-key configuration.

Result selectors use `outer.*` and `inner.*`. Subsequent filters, ordering and
projections address the selected result names (`order_id`, `customer_name` in
the example). Sources can be filtered, projected to named fields, or paged
before joining. Source ordering does not guarantee JOIN result ordering;
apply `orderBy()` to the result. Scalar result selectors are supported, but
field-based composition and further joins require a named result projection.

For self joins, pass the same set as both sources. For chained joins, use the
next set's `asQueryable()` as the inner source of the returned Queryable:

```php
$rows = $orders->join(
    $customers,
    Expr::field('customer_id'),
    Expr::field('id'),
    Expr::projection(['order_id' => 'outer.id', 'region_id' => 'inner.region_id']),
)->join(
    $db->set(Region::class)->asQueryable(),
    Expr::field('region_id'),
    Expr::field('id'),
    Expr::projection(['order_id' => 'outer.order_id', 'region' => 'inner.name']),
)->toArray();
```

`asQueryable()` exposes raw database rows for composition without entity
tracking. `DbSet::select()` likewise returns a projected Queryable. These APIs
do not implement `Include()`, navigation properties or relationship loading.

## PHPUnit with Docker MySQL 8

With Docker, Docker Compose, PHP `pdo_mysql` and Composer dependencies installed:

```sh
composer test:mysql
```

This starts `mysql:8.0` on loopback port 33386, waits for readiness, runs the
MySQL integration suite through PHPUnit using native PDO prepared statements,
and removes the container/network on exit. MySQL data lives in a temporary
filesystem. Each database-backed test creates a random database and drops it
in teardown; existing databases are not modified. The ordinary `composer test`
continues to use SQLite and does not start Docker.

To change the local port or pass PHPUnit arguments:

```sh
PHP_ENTITY_MYSQL_PORT=33387 composer test:mysql
composer test:mysql -- --filter testJoinExecutionAndTerminalOperators
```

To use an already-running local MySQL 8 instead:

```sh
PHP_ENTITY_MYSQL_DSN='mysql:host=127.0.0.1;port=33386;charset=utf8mb4' \
PHP_ENTITY_MYSQL_USER=root \
PHP_ENTITY_MYSQL_PASSWORD=php_entity_test \
vendor/bin/phpunit -c phpunit.mysql.xml
```

The test account needs permission to create and drop its generated databases.
This suite verifies MySQL 8 specifically, including INNER/LEFT joins, duplicate
and null keys, composite join keys, self/chained joins, filtered and paged
sources, projections, aggregates, schema discovery and DbSet tracking. It does
not run the entire SQLite persistence suite against MySQL.

## Schema changes with MigrationBuilder

The builder collects immutable schema operations; constructing it does not
access the database. The following callback API is supported for MySQL and
SQLite:

```php
use Light\Entity\Migrations\MigrationBuilder;
use Light\Entity\Migrations\TableBuilder;

$migration = new MigrationBuilder();
$migration->createTable('users', function (TableBuilder $table) {
    $table->integer('id')->autoIncrement()->primaryKey();
    $table->string('name', 100);
    $table->string('email', 255)->nullable();
});

// Preview only: returns a SQL script without modifying the database.
$script = $db->database->generateMigrationScript($migration);

// Execute explicitly using the context's PDO connection.
$db->database->executeMigration($migration);

// The created schema can immediately be used by the normal ORM.
$users = $db->set(User::class, 'users');
```

`integer()` and `string($name, $length = 255)` create non-nullable columns by
default. Column options are `nullable()`, `autoIncrement()` and `primaryKey()`;
each accepts an optional boolean. Mark multiple columns with `primaryKey()` to
create a composite primary key. Auto-increment requires an integer primary-key
column; only one auto-increment column is allowed. SQLite rejects auto-increment
in composite keys; MySQL requires that column first in the primary key. A plain integer primary key remains manually assigned, including
on SQLite. Tables without a primary key can be created, but entity mapping
still requires at least one primary-key column.

Multiple `createTable()` calls queue multiple statements in order. Empty
tables, duplicate column names, invalid string lengths, nullable primary keys
and multiple auto-increment columns are rejected before the operation is queued. Table
names may use `schema.table`; column names are quoted as literal identifiers.
The database still enforces its own identifier, row-size and type limits.
SQLite accepts VARCHAR lengths as metadata but does not enforce those lengths.

For offline SQL generation, use
`new MigrationSqlGenerator(new MySqlDialect())` from `Light\Entity\Migrations`
and `Light\Entity\Sql`, then call `generate($migration)` to obtain a list of SQL
statements. PostgreSQL and SQL Server DDL generation are not implemented yet.

The builder also supports `addColumn()`, `alterColumn()`, `renameColumn()`,
`dropColumn()`, `dropTable()`, `createIndex()` / `dropIndex()`, and
`addForeignKey()` / `dropForeignKey()`. It does not implement model snapshots,
automatic schema comparison, migration history, or Up/Down execution.
Re-executing the same CREATE fails rather than silently skipping it. Operations do not automatically start a transaction; a later
failure may leave earlier tables created. MySQL execution rejects active
transactions because DDL would implicitly commit them. Schema changes do not
refresh existing cached mappings; use a new context when changing mapped
schemas.

`composer test:mysql` also runs the migration contract against Docker MySQL 8,
including preview, schema discovery, generated/manual keys, ORM persistence,
identifier escaping and the transaction guard.


### Alter columns, indexes and foreign keys

```php
$migration = new MigrationBuilder();
$migration->addColumn('users', fn (TableBuilder $t) =>
    $t->string('nickname', 100)->nullable()->defaultValue(null));
$migration->alterColumn('users', fn (TableBuilder $t) =>
    $t->string('nickname', 200)->nullable()); // MySQL: complete replacement definition
$migration->renameColumn('users', 'nickname', 'display_name');
$migration->createIndex('users', 'ix_display_name', ['display_name'], unique: true);
$migration->dropIndex('users', 'ix_display_name');
$migration->addForeignKey('orders', 'fk_orders_user', ['user_id'], 'users', ['id'],
    onDelete: 'CASCADE', onUpdate: 'NO ACTION');
$migration->dropForeignKey('orders', 'fk_orders_user');
$migration->dropColumn('users', 'display_name');
$migration->dropTable('obsolete_table');

$script = $db->database->generateMigrationScript($migration);
$db->database->executeMigration($migration);
```

Operations execute in the order queued; prepare referenced tables/columns before
adding indexes or foreign keys. Each add/alter callback defines exactly one
column with the existing `integer()` / `string()` API. `defaultValue()` accepts
scalar literals or null; SQL expressions are not interpreted. An explicit null
default requires `nullable()`. Defaults also work in `createTable()`.

MySQL `alterColumn()` uses MODIFY COLUMN: supply the entire supported definition,
including nullability and any default to retain. An omitted default is removed;
this is not a patch of the existing metadata. Adding or altering primary-key /
auto-increment definitions is not supported; create those in `createTable()`.
Other database-specific column facets (generated expressions, unsigned, comments,
etc.) are not represented by these builders and must not be assumed preserved
by MODIFY. Numeric/string types remain limited to the existing integer/VARCHAR
builder types.

Indexes accept ordered column lists, including composite and unique indexes.
Foreign keys accept matching ordered child/principal column lists and the actions
`NO ACTION`, `RESTRICT`, `CASCADE`, `SET NULL` for delete/update. The database
validates compatible types, referenced keys, existing rows, nullability, and
whether an index/column is still needed by a constraint. Drop dependent
constraints/indexes first when necessary.

| Operation | MySQL 8 | SQLite |
| --- | --- | --- |
| Add / rename / drop column | Supported | Supported by native SQLite DDL (DROP COLUMN needs SQLite 3.35+) |
| Alter column definition | Supported | Rejected; requires rebuilding the table |
| Create / drop index, drop table | Supported | Supported |
| Add / drop foreign key on an existing table | Supported | Rejected; requires rebuilding the table |

Unsupported operations fail during SQL generation before any statement in that
migration executes. Database execution failures can still leave earlier MySQL
DDL applied. No SQLite table rebuild is attempted, and no existing mapping cache
is refreshed automatically.

## FK relationship getters

Entities extending `Entity` support lazy relationship getters after loading or
adding them through a `DbContext`. SQLite and MySQL foreign keys determine the
relationship direction; a UNIQUE constraint on the FK makes the inverse singular.

```php
// orders.client_id REFERENCES clients(id)
$client = $db->set(Client::class)->find(1);
$orders = $client->getOrders(); // DbSet: supports where(), count(), toArray(), etc.
$client = $orders->first()->getClient(); // entity or null
```

The resolver uses a registered table mapping first, then looks for the conventional
singular class name in the source entity's namespace and the global namespace.
Otherwise it uses `DynamicEntity`. Related rows participate in identity tracking
and `saveChanges()`, including dynamic rows. Plain classes that do not extend
`Entity` can still be relationship targets, but do not gain magic getters.

Missing collection rows return an empty `DbSet`; missing singular rows or nullable
FKs return `null`. Unknown or ambiguous relationships throw
`BadMethodCallException`. Getter calls accept no arguments. Schema metadata is
cached per context; create a new context after changing the schema. Without eager loading, results are
queried on demand so changing an entity's FK changes the next getter result.

With multiple FKs, use a column-based getter such as `getBillingClient()` for
`billing_client_id`, or configure an explicit getter. Explicit mappings also
support irregular table names and custom class names:

```php
$db->relationship(Order::class, 'Client', 'clients', ['client_id'], Client::class);
$db->relationship(Client::class, 'Purchases', 'orders', ['client_id'], Order::class);
$client->getPurchases();
```

The column list identifies the FK on its owning table in declaration order,
including composite FKs. Overrides still require a real database FK. Naming uses
basic English singularization (including people/person and children/child);
use explicit mappings for other naming conventions. Relationship getters without eager loading perform
lazy queries; looping over many parents can produce one query per parent.

## Eager loading relationships

Use `include()` to batch-load relationships before reading their getters:

```php
$clients = $db->set(Client::class)
    ->include('Orders.Notes')
    ->toArray();

foreach ($clients as $client) {
    foreach ($client->getOrders() as $order) {
        $notes = $order->getNotes()->toArray(); // Already loaded.
    }
}
```

Preloaded getters use context-scoped results; additional query operators such as
`where()` issue a new SQL query. Includes preserve normal identity tracking and
`saveChanges()` behavior. See [Eager Loading](docs/include.md) for batching,
cache lifetime, pagination, explicit FK mappings, and limitations.

## Entity entries and save interceptors

`DbSet::where()` also supports GraphQL-compatible array filters with `_and`,
`_or`, `_not`, comparison operators, and scalar equality shorthand.
See [Array filters](docs/array-filters.md) for examples and semantics.

Update an entity with an existing primary key without querying it first:

```php
$user = new User();
$user->id = $userDto->id;
$user->name = $userDto->name;
$db->update($user);
$db->saveChanges();
```

`update()` returns an `EntityEntry` and marks the entity `Modified`. The write is
deferred until `saveChanges()` and includes all writable non-key mapped columns,
so supply their intended values. A complete primary key is required (including
every component of a composite key); a missing key is rejected rather than
inserting a new entity. Another tracked instance with the same key is rejected.

Use EF Core-style entry names to inspect tracking state and snapshots:

```php
use Light\Entity\EntityState;

$entry = $db->entry($order);
$entry->state;          // EntityState::Unchanged, Modified, Added, Deleted, or Detached.
$entry->originalValues; // Snapshot array copy.
$entry->currentValues;  // Current mapped values array copy.

foreach ($db->changeTracker->entries(Order::class) as $entry) {
    // Inspect entries or make validated state transitions.
}
```

Register a `SaveChangesInterceptor` with `$db->addInterceptors($interceptor)` or
`new DbContext($pdo, interceptors: [$interceptor])`. Override `savingChanges()`,
`savedChanges()`, and/or `saveChangesFailed()` for timestamps, validation,
soft deletion, or observing save results. Pre-save hook changes are detected
before SQL runs. `savedChanges()` is not an outer transaction commit notification.

Tracking lives in `ChangeTracker`; SQL writes and save lifecycle coordination
live in `SaveChangesExecutor`. `DbContext` delegates these responsibilities.
`PropertyEntry` is deferred. See [Entity Entries](docs/entity-entries.md) and
[Save Interceptors](docs/save-interceptors.md) for examples and precise semantics.

## NoTracking queries

Use `asNoTracking()` to load entities without identity lookup or tracking snapshots:

```php
$users = $db->set(User::class)->asNoTracking()->toArray();

$db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::NoTracking;
$trackedUsers = $db->set(User::class)->asTracking()->toArray();
```

Import `Light\Entity\QueryTrackingBehavior` for the context setting. Query overrides
are immutable and the last override wins. Includes and relationship getters inherit
the source query's mode. NoTracking edits are not automatically saved, while
explicit `add()`, `remove()`, or entry state assignments still opt into writes.
Changing the default does not detach previously tracked entities.
On MySQL with native prepares and native PDO value types, untracked table queries
use `PDO::FETCH_FUNC` to initialize each complete row directly into internal storage,
avoiding per-field magic setters and per-column conversion. This applies to
schema-driven `Entity` subclasses with no constructor,
declared mapped properties, or custom setter. Only schema-marked boolean columns
are converted after fetching. Raw SQL, tracking, other drivers, and incompatible
entities retain the existing hydration path. BIT/YEAR mappings also retain the
existing string conversions. Constructors are still skipped.
See [Query Tracking](docs/query-tracking.md) for cache behavior, explicit attachment,
identity semantics, and performance considerations.

## Scalar SQL functions

Call an installed database function directly as a LINQ value expression:

```php
$score = $db->function('GetCustomScore', Expr::field('user_id'));

$topUsers = $db->set(User::class)
    ->where(Expr::gt($score, 80))
    ->orderByDescending($score)
    ->toArray();
```

An optional `schema` qualifier is supported where the database allows it. Function
calls execute in SQL and accept expressions or bound scalar/null arguments.
Scalar and named projections, nested calls, and join keys are supported. Function
registration is optional; use `registerFunction()` for a shared schema or early
argument-count validation. Mappings are context-scoped and do not create the database function.
See [Scalar SQL Functions](docs/sql-functions.md) for wrappers, parameter types,
dialect differences, and limitations.

## Raw SQL ordering

`orderByRaw()` starts a new primary ordering; `thenByRaw()` appends a secondary
ordering. Both accept a trusted scalar SQL expression, positional or named
provider-value parameters, and `descending` (default `false`). They compose
with ordinary ordering and leave the original DbSet unchanged.

```php
$groups = $db->set(IPGroup::class)->orderByRaw(
    '(SELECT name FROM DataCenter WHERE DataCenter.datacenter_id = IPGroup.datacenter_id)',
    parameters: [],
    descending: false,
)->thenByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', ['active'])->toArray();
```

Supply the expression without `ORDER BY` or `ASC`/`DESC`. Identifiers refer to
SQL table/column names, not mapped property names. Parameter binding and fragment
validation follow the same rules as `whereRaw()` below.

## Raw SQL conditions

Use `whereRaw()` for a database-specific condition or subquery without repeating
SELECT or the mapped table name:

```php
$users = $db->set(User::class)
    ->whereRaw('BINARY name = ?', [$name]) // MySQL-specific comparison
    ->where(['status' => 'active'])
    ->orderBy(Expr::field('id'))
    ->take(20)
    ->toArray();

$users = $db->set(User::class)->whereRaw(
    'user_id NOT IN (SELECT user_id FROM UserRole WHERE role = :role)',
    ['role' => 'Administrators'],
);
```

Each condition is wrapped in parentheses and combined with other `where()` /
`whereRaw()` calls using AND; the original DbSet is unchanged. It also works
with `fromSqlRaw()`, tracking/no-tracking, terminal queries, and supported bulk
updates/deletes. Pass only the condition, not a WHERE keyword or full SELECT.

Values use a positional `?` list or named `:name` map, with repeated named
parameters allowed. Markers are renamed into the same parameter namespace as
ordinary predicates and raw sources. Quoted literals and comments do not bind
parameters. Missing, extra, mixed-style parameters, unbalanced parentheses,
unterminated quotes/comments and statement separators are rejected before SQL
execution. A trailing line comment does not swallow the enclosing parentheses.

The SQL fragment is trusted application code; this API does not sanitize SQL or
make arbitrary user-provided syntax safe. Bind values separately. Identifiers,
SQL functions, collation and subquery semantics remain database-specific; names
inside the fragment are not automatically quoted, qualified or rewritten.
Parameters are provider values and bypass model ValueConverters. This is a raw
predicate escape hatch, not structured LINQ subquery translation.

## Composable raw SQL

Start a query from an application-supplied SELECT and compose normal operators:

```php
$blogs = $db->set(Blog::class)
    ->fromSqlRaw('SELECT * FROM blogs WHERE owner_id = ?', [$ownerId])
    ->orderBy(Expr::field('name'))
    ->take(10)
    ->toArray();
```

The SELECT becomes a derived-table source. Positional and named parameter binding,
projections, aggregates, joins, includes, and tracking options are supported.
Entity results require every mapped column. Apply `fromSqlRaw()` on the root set;
raw SQL queries do not support root `find()` identity shortcuts. Stored procedures,
CTE starts, statement separators, and SELECT INTO are not supported in this version.
See [Composable Raw SQL](docs/from-sql-raw.md) for examples and dialect limitations.

### Grouping, aggregates and distinct values

```php
use Light\Linq\Expr;

$totals = $orders
    ->groupBy(Expr::field('CustomerId'))
    ->where(Expr::gte(Expr::count(), 5))
    ->select(Expr::projection([
        'CustomerId' => Expr::key(),
        'OrderCount' => Expr::count(),
        'TotalAmount' => Expr::sum(Expr::field('Amount')),
    ]))
    ->toArray();

$countries = $customers->select(Expr::field('Country'))->distinct()->toArray();
```

Use `Expr::fields('Country', 'City')` as a composite grouping key and
`Expr::key('Country')` to reference a key member. Aggregates include `count()`,
`sum($field)`, `avg($field)`, `min($field)` and `max($field)`.
`Expr::count()` counts rows; `Expr::count($field)` counts non-null values.
Other aggregates ignore null values and return null when all values are null.
The terminal query method `->count()` counts the resulting groups or distinct rows.
`distinct()` compares the complete selected value or row. Pagination and distinct
operations are applied in their specified order; SQL results need an explicit
`orderBy()` when deterministic ordering is required.

The SQL provider translates predicates before grouping to `WHERE` and predicates
on groups to `HAVING`. A named aggregate projection followed by `where()` uses a
derived table, where projection aliases are ordinary columns. Grouped projections
must use `Expr::key()` and aggregates rather than ungrouped fields. Full grouping
objects and nested aggregates are rejected explicitly. Composing field-based
operators after a scalar projection requires a named projection instead.
`DbSet::groupBy()` and `DbSet::distinct()` return raw `Queryable` results;
these results do not enter entity tracking.

### Secondary ordering and terminal query operators

`Queryable` supports secondary ordering and these terminal operations:

```php
$sorted = $query->orderBy(Expr::field('Country'))
    ->thenBy(Expr::field('Name'))
    ->thenByDescending(Expr::field('Id'));

$first = $sorted->firstOrDefault(default: null);
$unique = $query->single(Expr::eq(Expr::field('Id'), 10));
$allPaid = $query->all(Expr::eq(Expr::field('IsPaid'), true));
$hasHK = $query->select(Expr::field('Country'))->contains('HK');
```

`thenBy()` and `thenByDescending()` must follow `orderBy()` or another secondary
ordering. A new `orderBy()` starts a new primary ordering. In-memory sorting is
stable for equal keys. `firstOrDefault($predicate, $default)` returns the default
only for an empty result; null and false are valid results. `single($predicate)`
throws `UnderflowException` for zero matches and `UnexpectedValueException` for
multiple matches. `all($predicate)` is true for an empty sequence. Terminal
operations apply to the sequence produced by earlier operators, including paging.

The SQL provider reads at most one row for `firstOrDefault()` and two for
`single()`. `all()` uses `NOT EXISTS` with a `CASE` expression so a false or
SQL UNKNOWN predicate fails the test. SQL `contains()` requires a scalar
projection and a scalar or null argument; null is compared with `IS NULL`.
Database equality and collation rules apply.

`DbSet` provides `thenBy()`, `thenByDescending()`, `firstOrDefault()`, `single()`
and `all()`. Entity results retain tracking and include loading. A DbSet default
must be an object or null. Use `select(...)->contains(...)` for membership checks.
Field-based terminal predicates after a projection require a named projection;
include ordering keys in that projection when preserving order is necessary.

### Terminal sum

```php
$total = $query
    ->where(Expr::eq(Expr::field('Status'), 'Paid'))
    ->sum(Expr::field('Amount'));
```

`Queryable::sum($selector)` executes immediately and sums the selected values.
Null values are ignored; empty or all-null results return zero. This terminal
method differs from `Expr::sum($field)`, which builds a grouped aggregate
expression. The in-memory provider returns an int or float.

For an entity set, use `$db->Orders->asQueryable()->sum(Expr::field('Amount'))`.
The SQL provider performs the sum in the database and returns one value. Simple
queries use `COALESCE(SUM(...), 0)` directly; pagination, distinct rows, and named
projections use a derived table to preserve the preceding operations. After a
projection, reference its output aliases in the selector. Scalar projections must
be replaced with named projections before using `sum($selector)`.
PDO numeric results are returned unchanged, including decimal strings, to avoid
losing database decimal precision through float conversion.

### Terminal average, minimum and maximum

```php
$average = $query->average(Expr::field('Amount'));
$minimum = $query->min(Expr::field('Amount'));
$maximum = $query->max(Expr::field('Amount'));
```

These Queryable operations ignore null values and return null for an empty or
all-null result. They preserve preceding filters, pagination, distinct rows and
named projections, just like terminal `sum()`. This nullable behavior follows
SQL aggregates; it differs from callback-based Enumerable aggregates, whose
empty-sequence and null behavior follows their existing implementations.

The SQL provider executes `AVG`, `MIN` or `MAX` in the database and returns one
value. Minimum and maximum retain the PDO value type; averages retain numeric
strings when returned by the driver. SQL Server uses `AVG(CAST(... AS FLOAT))`
to avoid integer truncation, so its averages use floating-point precision.

### Flattening query sources with selectMany

```php
$rows = $customers->selectMany(
    $orders,
    Expr::projection([
        'CustomerId' => 'outer.Id',
        'OrderId' => 'inner.Id',
    ]),
    predicate: Expr::eq(Expr::field('outer.Id'), Expr::field('inner.CustomerId')),
)->toArray();
```

`Queryable::selectMany($inner, $resultSelector, $predicate = null)` flattens the
matching inner sequence for every outer element. Both queries must share the
same provider instance. The result selector and optional predicate reference
`outer.*` and `inner.*` fields. Without a predicate, every outer/inner pair is
returned. Empty inner sequences produce no rows; duplicates are preserved.
Filter, order and page each source before flattening, or use a named result
projection to compose further field-based query operations afterwards.

This is an explicit expression-based API rather than C#'s collection-selector
lambda overloads. `Enumerable::selectMany()` continues to accept a PHP callback
for flattening nested iterables. In-memory Queryable predicates use relational
null comparisons: nullable field-to-field comparisons do not match nulls;
explicit null constants perform null tests. Other comparison behavior follows
PHP types; SQL providers use database types and collations.

The SQL provider translates an independent inner sequence to `CROSS JOIN` and a
pair predicate to `INNER JOIN ... ON ...`. Sources must return rows; use named
projections instead of scalar selections as join inputs. Project individual
columns explicitly, such as `Expr::field('inner.Id')`.
`DbSet::selectMany($innerSet, $resultSelector, $predicate)` returns an untracked
Queryable projection. Use `orderBy()` on the flattened result when its order
matters. Source pagination remains inside each source's derived table.

This implementation does not provide per-outer-row correlated `take()` or
arbitrary `APPLY`/`LATERAL` subqueries, automatic navigation expansion, or
`DefaultIfEmpty` outer-join behavior. Correlation belongs in the pair predicate;
filters on the inner query itself address its own columns.

### Single-or-default and last elements

```php
$one = $query->singleOrDefault(Expr::eq(Expr::field('Id'), 10), default: null);
$last = $query->orderBy(Expr::field('CreatedAt'))->thenBy(Expr::field('Id'))->last();
$optionalLast = $query->orderBy(Expr::field('Id'))->lastOrDefault(default: null);
```

`singleOrDefault()` returns the default for zero matches and throws
`UnexpectedValueException` for multiple matches. `last()` throws
`UnderflowException` when no element matches; `lastOrDefault()` returns the
default instead. Null and false are valid results and do not trigger defaults.
The in-memory provider uses sequence order and supports the same predicates and
defaults as the existing callback-based Enumerable methods.

The SQL provider requires an explicit `orderBy()` for `last()` and
`lastOrDefault()`. Include a unique tie-breaker with `thenBy()` when deterministic
results matter. It reverses every ordering and reads at most one row. Existing
pagination stays in a derived table before the last row is selected; a terminal
predicate filters the already paged sequence. Ordering keys must be available
in a named projection, or match a scalar projection when paging a scalar result.
Field-based terminal predicates after scalar projections require a named
projection, as with the other single-element terminal methods.

`singleOrDefault()` reads at most two rows. All three methods are also available
on DbSet, preserving entity materialization, tracking and includes. DbSet default
values must be objects or null.

### Queryable set operations

```php
$first = $firstQuery->select(Expr::fields('CustomerId'));
$second = $secondQuery->select(Expr::fields('CustomerId'));

$allRows = $first->concat($second);       // keep duplicates
$combined = $first->union($second);      // distinct values from either query
$shared = $first->intersect($second);    // distinct values present in both
$onlyFirst = $first->except($second);    // distinct values absent from second

$ids = $combined->orderBy(Expr::field('CustomerId'))->toArray();
```

Both sources must share a provider. Set operations compare complete selected
values or rows, not just primary keys. In-memory queries use the existing
Enumerable equality rules and first-occurrence order. `concat()` preserves
all duplicates; the other three operations remove duplicates. Source operators
remain before the set operation, and subsequent operators apply to its result.

The SQL provider requires explicit compatible projections: either two scalars,
or named projections with identical member names and order. Column value types
must be compatible in the database. SQL uses database equality and collation;
null values compare as equal for set membership. For filtering, ordering or
other field-based composition after a set operation, use named projections on
both inputs. Scalar sets support operations such as `count()` and `contains()`.

`concat()` translates to `UNION ALL`, and `union()` to `UNION`. SQLite,
PostgreSQL and SQL Server use native `INTERSECT` and `EXCEPT`. MySQL uses
`SELECT DISTINCT` with null-aware `EXISTS` / `NOT EXISTS` comparisons for these
two operations. Each input is wrapped to retain its own pagination; fluent set
chains are nested to preserve their grouping. Input ordering without pagination
is discarded. Add `orderBy()` after the set operation for a defined output order.

DbSet also exposes these four methods. It projects mapped columns automatically
and returns untracked Queryable rows; both mappings must have matching column
names and order. Includes and entity tracking are not applied to these results.

## Client-side groupJoin, zip and chunk

light-linq exposes these operations on Queryable, but this SQL provider does not
translate them. Direct use throws `LogicException` with guidance to call
`asEnumerable()` first. Existing SQL operations continue to work.

Apply filters and deterministic ordering in SQL, then explicitly switch to PHP:

```php
$users = $context->set(User::class)->asQueryable()
    ->where(Expr::eq(Expr::field('active'), true))
    ->orderBy(Expr::field('id'))
    ->asEnumerable();
$batches = $users->chunk(100);
```

After the switch, `groupJoin()` and `zip()` take ordinary iterables and PHP
callbacks, using Enumerable equality rules. `groupJoin()` retains each outer row
with an enumerable of matching inner rows; `zip()` pairs positions up to the
shorter input; `chunk()` yields arrays, retaining a partial final batch.
`asQueryable()` returns raw rows rather than tracked entities.

This does not add SQL-side batching: the SQL provider fetches results before PHP
chunking. Limit the query before switching if a full result set is too large.
Actual SQL translation for these operations requires separate compiler, dialect
and result-materialization support; updating the dependency alone does not add it.

## Streaming no-tracking queries

Iterating a `DbSet` with `asNoTracking()` and no `include()` reads and hydrates
one row at a time, without `fetchAll()` or retaining entities in the change tracker:

```php
foreach ($db->set(User::class)->asNoTracking() as $user) {
    // Process each row without accumulating the results.
}
```

`toArray()` still collects all entities. Queries with `include()` and tracking
queries retain their existing buffered behavior. Projections returned by
`select()` / `asQueryable()` are unchanged. Raw SQL entity queries also stream.

For MySQL, the streaming statement executes with PDO buffering disabled; the
connection's buffering option is restored before the first entity is yielded.
While the result is active, do not run another query on the same connection
(including lazy relationship getters or `saveChanges()`). Finish the iteration,
use another connection, or buffer the results first. The cursor closes on
completion or when the iterator is destroyed, including a normal `foreach`
`break` or exception. If you keep an explicit iterator reference, release it
with `unset($iterator)` when stopping early. This is synchronous streaming,
not asynchronous database I/O.

## Bulk updates and deletes

Execute a single parameterized SQL statement without loading entities:

```php
$affected = $db->set(Order::class)
    ->where(['status' => 'pending'])
    ->executeUpdate(['status' => 'cancelled']);

$deleted = $db->set(Order::class)
    ->where(['status' => 'cancelled'])
    ->executeDelete();
```

Update values may be scalar, null, or supported scalar value expressions such
as `Expr::field('other_column')`. Assignment targets must be mapped properties;
primary keys and computed columns cannot be assigned. Empty assignments fail.

The 1.0 bulk-write API accepts a table source with zero or more `where()` calls.
Ordering, pagination, raw SQL sources, includes and preloaded relationship
collections are rejected rather than ignored. Without a `where()` condition,
the operation affects the entire table. Joins and projections do not expose
these methods.

Writes execute immediately, return the PDO driver's affected-row count, and
participate in an existing connection transaction without starting or committing
one. MySQL's count can depend on the connection's matched/changed-row setting.
No `saveChanges()` call is needed. Save interceptors, entity tracking, snapshots
and relationship caches are bypassed: already loaded entities remain stale,
and a later `saveChanges()` can overwrite bulk changes. Use a fresh context or
explicitly reload affected entities before continuing tracked work. Multiple
bulk operations are not automatically wrapped in one transaction.

## Value converters and comparers

JSON columns remain strings by default. Configure conversion per property when
an application needs an array, typed object, enum, or another model type.
`ValueConverter` maps model values to scalar provider values and back;
`ValueComparer` defines model equality and an independent snapshot. SQL NULL
bypasses all callbacks in both directions. Non-scalar model values require both
handlers, so mutable objects cannot accidentally share their original snapshot.

For example, map a JSON column to a PHP object:

```php
use Light\Entity\{DbContext, Entity, EntityMapping, ValueConverter, ValueComparer};
use Light\Entity\Schema\SchemaReader;

class User extends Entity
{
    public ?object $settings;
}

$converter = new ValueConverter(
    toProvider: fn (object $value) => json_encode($value, JSON_THROW_ON_ERROR),
    fromProvider: fn (string $value) => json_decode($value, false, 512, JSON_THROW_ON_ERROR),
);
$comparer = new ValueComparer(
    equals: fn (object $a, object $b) => $a == $b,
    snapshot: fn (object $value) => json_decode(
        json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR,
    ),
);
$mapping = EntityMapping::fromSchema(
    User::class, 'users', (new SchemaReader($pdo))->columns('users'),
    converters: ['settings' => $converter],
    comparers: ['settings' => $comparer],
);
$db = new DbContext($pdo, mappings: [$mapping]);

$user = $db->set(User::class)->find(1);
$user->settings->theme = 'dark';
$db->saveChanges();
```

This example assumes non-null JSON object documents or SQL NULL. A typed domain
object can use its own constructor/factory in `fromProvider`; JSON documents
with arrays or scalar roots need callbacks accepting those shapes. For an array
property, use associative decoding and an appropriate independent snapshot.
Ordinary dynamic `Entity` getters still return by value, so modify an array and
assign it back, or declare a public array property to modify offsets directly.

Converters run during entity hydration (including raw SQL, streaming and includes),
INSERT/UPDATE, and literal `executeUpdate()` assignments. Snapshots and entry
values use model values; SQL binding uses provider values. Original-value reads
copy snapshots so modifying an exposed object does not change tracking history.
Snapshot callbacks run before commit; failed saves retain the original snapshot.

Direct field/constant comparisons in `DbSet::where()` and entity terminal
predicates convert constants to provider values. Array filters also support model
operands, e.g. `where(['status' => $enum])` or
`where(['settings' => ['eq' => $settingsObject]])`. Array-valued equality operands
must use the explicit `eq` form. Conversion is not JSON-path translation: JSON
comparison still follows the database's whole-column semantics.

`select()`, `asQueryable()`, JOIN projections and SQL value expressions remain
provider-level operations; their results and expression literals are not
implicitly converted. Primary-key converters/comparers are not supported in
this version. Conversion disables the native bulk hydration optimization for
that mapping, ensuring every configured property passes through its converter.
The comparer must implement equality and copying consistently; use a deep copy
for nested mutable objects. No hash callback is required because tracking uses
its existing identity lookup rather than hashing converted property values.

### Reusing Entity storage in an Active Record adapter

Dynamic `Entity` properties support reference access, including nested changes
such as `$user->settings['theme'] = 'dark'`. Converters decode provider values;
`EntityMapping::applyValues()` assigns already-converted model values, validates
field names, and respects public typed properties.

```php
$user = $db->set(User::class)->asNoTracking()->first();
// $mapping is the EntityMapping used to configure $db.
$original = $mapping->snapshotEntity($user);
$user->settings['theme'] = 'dark';
$db->attach($user, $original);
$db->saveChanges();
```

`attach()` accepts a complete
snapshot of model values, copies it with the configured comparers, and tracks
the actual object without overwriting pending changes. Keys must match and the
entity and its identity must not already be tracked.

For a snapshot taken immediately after hydration, `snapshotEntity($entity,
fromProvider: true)` can use the optional `ValueComparer` constructor callback
`snapshotFromProvider`. This callback must produce an independent snapshot of
fresh converter output. Arrays containing no objects or references can safely
use PHP copy-on-write; mutable objects require copying. Without the callback,
the ordinary snapshot callback runs. Custom accessors and mapped public
properties always use the ordinary path.

### Bulk materialization and streaming

`asNoTracking()->toArray()` uses native MySQL bulk hydration when the mapping
and query are compatible. Dynamic Entity subclasses may have constructors:
hydration bypasses them just as ordinary hydration does. Configured converters
receive raw provider values once per non-null column, before schema coercion.
Public mapped properties, custom setters, raw SQL, BIT/YEAR and non-native PDO
fetch settings retain the general conversion path. Typed entities and SQLite
continue to use ordinary hydration. `foreach` keeps the streaming path; requesting
an array intentionally materializes the result. Partial mappings still validate
missing columns and ignore extra columns through the existing fallback.

### ModelBuilder and global query filters

Override `onModelCreating()` to configure named predicates for an entity type:

```php
use Light\Entity\{DbContext, ModelBuilder};
use Light\Linq\Expr;

class AppDbContext extends DbContext
{
    public int $tenantId = 1;

    protected function onModelCreating(ModelBuilder $builder): void
    {
        $builder->entity(User::class)
            ->hasQueryFilter('softDelete', Expr::eq(Expr::field('is_deleted'), 0))
            ->hasQueryFilter('tenant', fn (self $context) => ['tenant_id' => $context->tenantId]);
    }
}

$db = new AppDbContext($pdo);
$users = $db->set(User::class)->asNoTracking()->toArray();
$includingDeleted = $db->set(User::class)->ignoreQueryFilters(['softDelete'])->toArray();
$all = $db->set(User::class)->ignoreQueryFilters()->toArray();
```

The hook runs lazily once per context after the derived constructor has finished.
Configuration is context-local; completed builders reject further mutation. A
failed hook can be retried without publishing partial configuration. Entity schema
mapping remains lazy and accepts existing constructor-supplied mappings.

All filters are named. Reusing a name replaces its definition. Predicates may be
ValueExpressions, condition maps or callbacks returning either. Callbacks receive
the executing context immediately before SQL compilation, allowing current tenant
values to be bound without freezing them into shared metadata. Converter rules
apply to model-value constants. Query filters do not automatically implement soft
deletion; application code must set the deletion flag separately.

Filters apply before pagination and affect entity reads, tracking/no-tracking,
streaming, terminal operators, projections/aggregates, composable raw SQL,
join/set-operation operands and executeUpdate/executeDelete. Explicit joins use
each operand DbSet's filter scope. Raw row queries created independently of a
DbSet and DatabaseFacade raw SQL do not carry entity filter metadata. saveChanges
persists explicitly tracked entities and does not add query filters to their writes.

`ignoreQueryFilters()` returns a new DbSet. Null disables all filters; a list
selectively disables names, repeated calls accumulate exclusions, and an empty
list changes nothing. The scope propagates to eager and lazy relationships and
Include caches distinguish scopes. `find()` with configured entity filters queries
the database instead of returning a potentially hidden tracked identity directly.
Already tracked objects can still contain unsaved values; query filters are SQL
predicates and are not a replacement for database access controls.

No-filter contexts keep ordinary table source expressions and skip filter
rewriting. The feature is covered on SQLite and MySQL, including filtered LEFT
JOINs, string-key Include batches, converter binding and runtime tenant changes.

### IN subqueries

Use `whereIn()` or `whereNotIn()` with an explicit single-column selection from
another query using the same PDO connection, including a different context or Model:

```php
$adminIds = $context->set(UserRole::class)
    ->where(['name' => 'admins'])
    ->select(Expr::field('user_id'));

$users = $context->set(User::class)
    ->whereIn('user_id', $adminIds)
    ->toArray();
```

This produces a single SQL query equivalent to:

```sql
SELECT * FROM User
WHERE user_id IN (SELECT user_id FROM UserRole WHERE name = :p0)
```

The subquery is not executed or materialized separately. Parameters are bound
across both queries without name collisions. Each source resolves global query
filters in its owning context when the query executes, so different contexts
retain their own filters and captured values stay current. Call
`ignoreQueryFilters()` on the subquery to opt out. Calling
`ignoreQueryFilters()` on the composed outer query also affects its subqueries.
The outer query supports further filters, ordering, and bulk update/delete
(subject to the database's restrictions on modifying a table read by a subquery).

For expression composition, use the light-entity SQL node directly:

```php
use Light\Entity\Sql\InExpression;

$users = $context->set(User::class)
    ->where(new InExpression(Expr::field('user_id'), $adminIds))
    ->toArray();
```

The optional third constructor argument negates membership (`NOT IN`). Subqueries
must select exactly one column. These expressions use SQL NULL semantics:
`NOT IN` with a NULL in the subquery does not match otherwise absent values.
An empty subquery matches no rows for `IN` and all rows for `NOT IN`.
Correlated queries are supported through the closure `any()` syntax below. The existing array
filter `['id' => ['in' => [1, 2, 3]]]` continues to support value lists with its
existing NULL handling.

### Closure translation

Query closures are parsed from PHP source into expression trees, then compiled
into parameterized SQL. The translator never invokes them to filter entities
in PHP. Query execution remains deferred until enumeration or a terminal method.
See [Closure translation](docs/closure-translation.md) for supported syntax,
SQL examples, parameter binding, and limitations.

```php
$db->set(Customers::class)
    ->where(fn($c) => str_starts_with($c->name, 'a'));

$db->set(Orders::class)
    ->where(fn($o) => $o->total + $o->tax >= 30);
```

The first predicate is conceptually `WHERE name LIKE 'a%'`. Actual SQL uses
`WHERE (name LIKE :p0 ESCAPE :p1)`, with `p0 = 'a%'` and `p1 = '!'`, plus
identifier quoting for the database dialect. Arithmetic becomes nested
`BinaryExpression` nodes; string matching becomes SQL `LikeExpression`.

Only documented query syntax is translated. A readable PHP source file is
required, and unsupported syntax raises `ClosureTranslationException` without
automatically switching to client-side filtering.

### Scalar functions in query closures

```php
$db->set(Customers::class)
    ->where(fn($c) => strtolower(trim($c->name)) == 'alice')
    ->select(fn($c) => ['name' => strtoupper($c->name), 'bytes' => strlen($c->name)]);

$db->set(Orders::class)->select(fn($o) => round(abs($o->balance), 2));
```

Supported scalar calls are `strtolower`, `strtoupper`, `trim`, `strlen`, `abs`,
and `round`. They form `ScalarFunctionExpression` nodes and compile to built-in
SQL operations. `strlen` counts storage bytes, including trailing spaces, using
`LENGTH` / `OCTET_LENGTH` / `DATALENGTH` according to dialect. It is not a
Unicode character count; different storage encodings may give different counts.

`round` accepts an optional constant integer precision; custom rounding modes
and trim masks are unsupported. Null inputs produce NULL. Case conversion,
whitespace, numeric precision and result types have documented provider
boundaries; see [Scalar functions](docs/closure-translation.md#scalar-functions).

### Null fallback and conditional projections

```php
$db->set(Orders::class)->select(fn($o) => [
    'customer' => $o->customer_name ?? 'Guest',
    'size' => $o->total >= 100 ? 'large' : 'small',
]);
```

`??` becomes `CoalesceExpression` and SQL `COALESCE`; full ternary becomes
`ConditionalExpression` and SQL `CASE WHEN ... THEN ... ELSE ... END`.
Constants use bound parameters. Fallback applies only to NULL, preserving zero,
false, and empty strings. Nesting and scalar selections are supported; shorthand
`?:` is rejected. Conditions and branch types must be valid for the SQL provider.
See [Closure translation](docs/closure-translation.md#coalesce-and-conditional-expressions).

### String matching in query closures

```php
$db->set(Customers::class)->where(fn($c) => str_starts_with($c->name, 'a'));
$db->set(Customers::class)->where(fn($c) => str_ends_with($c->name, 'z'));
$db->set(Customers::class)->where(fn($c) => str_contains($c->name, $needle));
```

The current checkout requires light-linq `^1.3`, including `StringMatchExpression` support.
The SQL translator lowers these operations to `LikeExpression`, escaping literal
`%`, `_`, `!`, and `[` before adding wildcards. Search strings are parameterized;
field-valued needles are escaped in SQL using `REPLACE`. Nulls follow SQL's
three-valued predicate logic. Case sensitivity follows the database collation
and may differ from PHP's case-sensitive in-memory matching.

### Closure queries: Contains and Any

Requires `mathsgod/light-linq` v1.3.0 or later within the 1.x
series (`^1.3`). Install dependencies with `composer install`; a sibling LINQ
checkout or local path repository is not required. This version supplies the
`Light\Linq\Expressions` namespace and closure translator.

`DbSet::where()` and `select()` now accept the restricted query closures
supported by light-linq:

```php
$adminIds = $context->set(UserRole::class)
    ->where(['name' => 'admins'])
    ->select(fn($r) => $r->user_id);

$users = $context->set(User::class)
    ->where(fn($u) => $adminIds->contains($u->user_id)
        && $u->status == 'active')
    ->toArray();
```

LINQ's `ContainsExpression` is lowered to light-entity's `Sql\InExpression`.
For correlated existence checks, capture the inner DbSet directly:

```php
$roles = $context->set(UserRole::class);

$users = $context->set(User::class)
    ->where(fn($u) => $roles->any(
        fn($r) => $r->user_id == $u->user_id && $r->name == 'admins'
    ))
    ->toArray();
```

`AnyExpression` is lowered to `Sql\ExistsExpression`. SQL aliases are generated
for inner and outer rows, including nested `any()` calls and queries against the
same table. `!$roles->any(...)` generates `NOT EXISTS`. An `any()` call without
a predicate checks whether the captured query has any rows. All of these are
compiled into a single SQL statement; the translator never executes the inner
PHP call. Subqueries must use the same provider/context as the outer query.

Global query filters apply to subqueries. Call `ignoreQueryFilters()` on the
inner set to disable its filters, or on the composed
outer `DbSet` to disable filters across the composed query. `DbSet` closures use
mapped value converters for their own conditions and new `any()` predicates;
existing converted conditions on captured queries are preserved. As before,
`asQueryable()` is the raw query API and callers supplying its own predicates
must provide database values.

Unsupported closure syntax fails with `ClosureTranslationException`; operations
that cannot be represented by the SQL planner fail explicitly with
`LogicException`. For example, a predicate appended to a paginated inner query
is currently rejected rather than being moved before its pagination. Put
closures on separate lines if their source ranges would otherwise be ambiguous.
Comparisons and membership use database type, collation and NULL semantics;
`NOT IN` and `NOT EXISTS` can therefore differ when NULL values are involved.

### DbSet as IQueryable

`DbSet<T>` implements `Light\Linq\IQueryable<T>`. It exposes `expression()` and
`provider()` and can be passed directly to LINQ expression helpers, joins, and
query closures. `asQueryable()` remains available when raw rows are desired;
it is no longer required to capture a set in a correlated `any()` closure.

```php
$roles = $context->set(UserRole::class);
$admins = $context->set(User::class)->where(fn($u) => $roles->any(
    fn($r) => $r->user_id == $u->user_id && $r->name == 'admins'
));

// Executed immediately, returning bool:
$hasAdmins = $roles->any(fn($r) => $r->name == 'admins');
```

Entity-returning operations on a DbSet retain materialization, tracking,
`include()` and query-filter behavior. Projections, grouping, distinct, joins
and set operations retain their existing untracked `Queryable` results.
`concat()`, `union()`, `intersect()`, `except()`, `join()`, `leftJoin()` and
`selectMany()` accept any `IQueryable` from the same provider. When mixing raw
queries with entity sets, provide the explicit projections required by the SQL
provider's shape rules.

`firstOrDefault()`, `singleOrDefault()` and `lastOrDefault()` accept a mixed
fallback value to match `IQueryable`; a non-empty result still returns an entity.
Ordering parameters use the interface's `keySelector` name for named arguments.
`contains($entity)` checks all primary-key values, including composite keys,
without materializing or tracking an additional entity. Null, a different entity
type or an incomplete key does not match. Scalar membership requires a scalar
projection, such as `$roles->select(fn($r) => $r->user_id)->contains($id)`.

`sum()`, `average()`, `min()` and `max()` delegate to the query provider, or use
cached entities for a preloaded relationship collection. `asEnumerable()` keeps
entity materialization and is the explicit boundary for client-side operations.
`groupJoin()`, `zip()` and `chunk()` are exposed for interface compatibility;
the SQL provider still rejects unsupported operators explicitly. Use
`asEnumerable()` before them to perform those operations in PHP.
