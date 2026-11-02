# php-entity

An experimental PHP 8.2+ database-first ORM built on PDO and `mathsgod/php-linq`. Independent of light-db and Laminas DB.

## Runtime automatic mapping

The first `set()` or `model->getEntityType()` for an entity reads the database schema to discover columns, database types, nullability, primary key, generated identity, defaults and computed columns. Metadata is cached per context. No scaffolding command or property list is required.

```php
use PhpEntity\DbContext;
use PhpEntity\Entity;
use PhpLinq\Expr;

// Defaults to the table "users". No manually declared properties required.
final class User extends Entity {}

$pdo = new PDO('mysql:host=localhost;dbname=app;charset=utf8mb4', 'username', 'password');
$db = new DbContext($pdo);
$users = $db->set(User::class);

$user = $users->where(Expr::eq(Expr::field('id'), 1))->first();
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
use PhpEntity\Table;

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
- Boolean declarations become booleans; MySQL `tinyint(1)` remains an integer because it can store other values.
- Floating-point columns become floats. Decimal/numeric, dates, JSON and binary values remain strings; JSON is not decoded automatically. SQLite's own numeric storage may already lose decimal precision.
- Nullable database values remain null. Database constraints enforce nullability and required columns.
- Unset dynamic or uninitialized declared properties are omitted from inserts, allowing database defaults. Explicit null is sent as null; it does not request the default.
- Generated keys and computed columns are omitted from inserts. Attempts to modify computed columns are rejected. Values are reloaded after inserts/updates to include defaults and generated values.

## Context behavior

`DbSet` supports `where`, `orderBy`, `orderByDescending`, `skip`, `take`, `first`, `find`, `any`, `count`, `toArray`, and iteration. `first()` throws `UnderflowException` when empty. Root `find()` checks the identity map before querying and returns null when missing.

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

Keep this repository alongside `php-linq` and run `composer install`. The path repository in `composer.json` is for local development; consuming applications must configure their own path repository until a suitable php-linq release is available.

Run `composer test` or `vendor/bin/phpunit` after `composer install`. Database integration tests require `pdo_sqlite` and are marked skipped if it is unavailable; install the extension to run the full suite. PHPUnit tests cover runtime discovery, types, defaults, computed columns, identity tracking, persistence and rollback/retry with SQLite. MySQL/MariaDB discovery uses INFORMATION_SCHEMA.COLUMNS. The Docker MySQL 8 JOIN suite covers live queries, schema discovery and tracking; the full persistence/defaults suite runs against SQLite. The MySQL suite also covers composite-key persistence, tracking, migrations and rollback/retry, including auto-increment key components.

No relationship loading, migration version/history management, custom entity column aliases, optimistic concurrency, async operations or `executeUpdate` yet. Updates/deletes match only the primary key. Use a short-lived context for each unit of work.

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

`PhpEntity\SqlQueryProvider` implements `PhpLinq\IQueryProvider` and executes php-linq expression trees through PDO.
It returns rows/scalars directly; use `DbSet` for tracked entity objects.
SQL compilation and dialects live in this package under `PhpEntity\Sql`; php-linq supplies the database-independent query operators and expression trees.

The available dialects are `MySqlDialect`, `SqlServerDialect`,
`PostgreSqlDialect`, and `SqliteDialect`. They handle identifier quoting and
pagination syntax and emit query values as bound parameters. Dialect availability
does not imply automatic schema discovery support for every database.

SQL compiler and dialect classes have moved from `PhpLinq\Sql` to
`PhpEntity\Sql`; update those imports when upgrading. JOIN SQL translation also lives in this package.


```php
use PhpEntity\SqlQueryProvider;
use PhpLinq\Queryable;
use PhpEntity\Sql\MySqlDialect;

$users = Queryable::from(
    new SqlQueryProvider($pdo, new MySqlDialect()),
    'users',
);
```

The former `PhpLinq\SqlQueryProvider` class has moved to this package; update
imports to `PhpEntity\SqlQueryProvider` and add the php-entity dependency.

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
transaction before writing. SQLite is integration-tested; other drivers still
need live-server validation. Nested `beginTransaction()` is rejected.

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
`PhpEntity\IModel`. `Model` owns the per-context mapping cache previously kept
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
current entity/column mappings; relationship metadata is not implemented yet.

## JOIN queries

`DbSet::join()` and `leftJoin()` return a `PhpLinq\Queryable` of projected
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
shared JOIN contract through PHPUnit using native PDO prepared statements,
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

## Create tables with MigrationBuilder

The builder collects immutable schema operations; constructing it does not
access the database. The following callback API is supported for MySQL and
SQLite:

```php
use PhpEntity\Migrations\MigrationBuilder;
use PhpEntity\Migrations\TableBuilder;

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
`new MigrationSqlGenerator(new MySqlDialect())` from `PhpEntity\Migrations`
and `PhpEntity\Sql`, then call `generate($migration)` to obtain a list of SQL
statements. PostgreSQL and SQL Server DDL generation are not implemented yet.

This first version supports CREATE TABLE only. It does not implement ALTER or
DROP, model snapshots, automatic schema comparison, migration history, or
Up/Down execution. Re-executing the same CREATE fails rather than silently
skipping it. Operations do not automatically start a transaction; a later
failure may leave earlier tables created. MySQL execution rejects active
transactions because DDL would implicitly commit them. Schema changes do not
refresh existing cached mappings; use a new context when changing mapped
schemas.

`composer test:mysql` also runs the migration contract against Docker MySQL 8,
including preview, schema discovery, generated/manual keys, ORM persistence,
identifier escaping and the transaction guard.
