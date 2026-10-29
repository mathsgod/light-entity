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

The database table must already exist; this package does not create schema. Dialects are selected automatically for PDO MySQL/MariaDB and SQLite.

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

Explicit `EntityMapping` objects remain an optional override for existing callers. Automatic schema discovery requires exactly one primary-key column. SQLite uses rowid-backed INTEGER primary keys for generated IDs; MySQL/MariaDB use AUTO_INCREMENT. Generated IDs use PDO `lastInsertId()`.

## Setup and tests

Keep this repository alongside `php-linq` and run `composer install`. The path repository in `composer.json` is for local development; consuming applications must configure their own path repository until a suitable php-linq release is available.

Run `composer test` or `vendor/bin/phpunit` after `composer install`. Database integration tests require `pdo_sqlite` and are marked skipped if it is unavailable; install the extension to run the full suite. PHPUnit tests cover runtime discovery, types, defaults, computed columns, identity tracking, persistence and rollback/retry with SQLite. MySQL/MariaDB discovery uses INFORMATION_SCHEMA.COLUMNS; it has not yet been integration-tested against a live MySQL server.

No relationships, migrations, custom column aliases, composite keys, optimistic concurrency, async operations, projections or `executeUpdate` yet. Updates/deletes match only the primary key. Use a short-lived context for each unit of work.

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
The SQL compiler and dialects currently remain in php-linq.

```php
use PhpEntity\SqlQueryProvider;
use PhpLinq\Queryable;
use PhpLinq\Sql\MySqlDialect;

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
$mapping->key;
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
