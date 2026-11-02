# Eager Loading with include()

`DbSet::include()` loads related entities in batches, avoiding a separate relationship query for every parent. It uses the same foreign-key discovery and explicit relationship mappings as relationship getters.

## Loading a Collection

```php
$clients = $db->set(Client::class)
    ->include('Orders')
    ->toArray();

foreach ($clients as $client) {
    foreach ($client->getOrders() as $order) {
        echo $order->id;
    }
}
```

Use the relationship name without the `get` prefix: `Orders` corresponds to `getOrders()`.

`include()` records the path without executing a query. When `toArray()`, iteration, `first()`, or `find()` materializes entities, the context loads their requested relationships. For a tracked entity returned by `find()`, the includes are still processed.

For a small result set, loading clients and their orders uses one entity query for clients and one batch query for orders, conceptually:

```sql
SELECT * FROM clients;
SELECT * FROM orders WHERE client_id IN (?, ?, ...);
```

Schema discovery may issue additional metadata queries on first use. Large key sets are split into batches, with up to 200 key parameters per query. Composite relationships use complete key tuples rather than independent `IN` conditions for each column. String-key batches use tagged `UNION ALL` branches so that matching follows database collations, including case-insensitive comparisons.

## Loading a Single Entity Relationship

```php
$orders = $db->set(Order::class)
    ->include('Client')
    ->toArray();

foreach ($orders as $order) {
    $client = $order->getClient(); // Loaded entity, or null.
}
```

A nullable FK or missing singular result is cached as an empty result, so reading the getter does not repeatedly query the database. An inverse relationship backed by a UNIQUE FK is singular as well.

## Nested and Multiple Relationships

Use dotted paths for nested relationships and repeat `include()` for additional paths:

```php
$clients = $db->set(Client::class)
    ->include('Orders.Notes')
    ->include('Orders.Client')
    ->include('Profile')
    ->toArray();
```

Duplicate and overlapping path prefixes are merged. `Orders` is loaded once before its `Notes` and `Client` relationships are loaded in batches. Path matching ignores case and underscores.

Unknown or ambiguous relationships throw `BadMethodCallException` when the entity query is evaluated, including when it returns no parents. Invalid path syntax is rejected by `include()` itself. Relationship sources must extend `Entity` to expose getters. A leaf target may be a plain mapped class; traversing further relationships requires it to extend `Entity`.

## Explicit FK Mappings

Use the same `relationship()` configuration as for lazy getters:

```php
$db->relationship(
    Client::class,
    'Purchases',
    'orders',
    ['client_id'],
    Order::class
);

$clients = $db->set(Client::class)
    ->include('Purchases')
    ->toArray();

$orders = $clients[0]->getPurchases();
```

The configured FK must exist in the database. Includes support both application entity classes and `DynamicEntity` targets.

## Working with a Preloaded DbSet

A preloaded collection getter still returns a `DbSet`:

```php
$orders = $client->getOrders();

$orders->toArray(); // Read loaded entities without another query.
$orders->count();   // Count loaded entities without another query.
$orders->any();     // Inspect loaded entities without another query.
$orders->first();   // Read the first loaded entity; throws if empty.

foreach ($orders as $order) {
    // No additional query.
}
```

Additional query operators create a new database query rather than filtering the cached entities in PHP:

```php
$pending = $orders
    ->where(Expr::eq(Expr::field('status'), 'pending'))
    ->orderBy(Expr::field('id'))
    ->toArray();
```

The same applies to `skip()`, `take()`, and predicate overloads of `first()` and `any()`. `select()` and `asQueryable()` use SQL and return untracked projections or raw rows. Include paths are preserved when composing entity queries; projections, `count()`, and `any()` do not themselves preload relationships.

## Pagination

Parent filtering, ordering, `skip()`, and `take()` are applied before relationships are loaded:

```php
$clients = $db->set(Client::class)
    ->include('Orders')
    ->orderBy(Expr::field('id'))
    ->skip(20)
    ->take(10)
    ->toArray();
```

Only the returned clients have their orders loaded. Include queries buffer the selected parent entities before yielding them so that relationships can be loaded in batches.

## Saving and Deleting Preloaded Entities

All loaded entities pass through the context's normal materialization and identity tracking. Existing tracked objects are reused without overwriting pending changes.

```php
$client = $db->set(Client::class)
    ->include('Orders')
    ->find(1);

if ($client !== null) {
    $orders = $client->getOrders();

    foreach ($orders as $order) {
        if ($order->status === 'cancelled') {
            $orders->remove($order);
        } else {
            $order->status = 'completed';
        }
    }

    $db->saveChanges();
}
```

This also supports deletion of tracked `DynamicEntity` results. Database constraints still apply, including restrictions on deleting rows referenced by other tables.

See [Change Tracking](change-tracking.md) for snapshot comparison and save behavior.

## Cache Lifetime and Scope

Loaded relationship results belong to their context. The getter checks the source key used during loading; changing a source FK prevents reuse of its old result. Changes to the key fields of cached targets also invalidate that result.

A successful `saveChanges()` that performs writes clears the context's relationship caches, as does changing an explicit relationship mapping. A subsequent getter queries again unless another include has reloaded that relationship. Already returned `DbSet` objects retain their loaded list; obtain the getter again to use the current cache or query.

Loaded collections are snapshots of membership. They do not automatically discover external database changes or every in-memory reassignment of other entities. Create a new context when fresh database state is required. There is no public explicit reload API in this version.

Queries for parents and relationships execute separately. They do not guarantee a single consistent database snapshot under concurrent writes; use an appropriate surrounding transaction when that consistency is required.

This version supports dotted include paths. It does not implement `ThenInclude()`, filtered include expressions, or automatic relationship loading for every query.

## Query Tracking Mode

`include()` propagates the query's tracking mode through nested relationships.
Use `asNoTracking()` for an untracked graph; getters still use the preloaded cache.
Lazy getters likewise retain their source object's mode, even if the context's
default later changes. Switching a preloaded collection to the other mode with
`asTracking()` or `asNoTracking()` creates a new query rather than reusing the
cached objects. See [Query Tracking](query-tracking.md).
