# Relationship Entities and saveChanges() Change Tracking

The examples below use the default `TrackAll` query behavior. Queries using
`asNoTracking()` or the context's `NoTracking` default skip automatic tracking;
see [Query Tracking](query-tracking.md).


Entities loaded through the same `DbContext` are tracked automatically, including entities loaded through relationship getters. After modifying them, call `saveChanges()` on that context to persist the changes.

## Usage

Assume `orders.client_id` is a foreign key referencing `clients.id`, and `Client` extends `Entity`:

```php
$client = $db->set(Client::class)->find(1);

if ($client !== null) {
    foreach ($client->getOrders() as $order) {
        $order->status = 'completed';
    }

    $count = $db->saveChanges();
}
```

You do not need to call `add()` for an order that has already been loaded. `add()` registers a new entity for insertion; it does not register an update.

`$count` is the number of entity write operations performed, including inserts, updates, and deletes. It is not the number of changed fields. `saveChanges()` processes all tracked entities in the context, including entities loaded by other queries.

## Capturing Original Values During Loading

`getOrders()` returns a lazily evaluated `DbSet`. Obtaining the set does not load the orders. Operations such as `first()`, `toArray()`, and `foreach` read the rows and materialize entities.

When a row passes through `DbContext::materialize()`, the context:

1. Uses the mapping and primary key to check whether the row is already tracked.
2. Returns the existing object if it is already tracked.
3. Otherwise, creates an entity and records its object reference, mapping, state, and original field values.

`ChangeTracker` owns the tracking records in a `SplObjectStorage`. The following example illustrates the stored information; it is not an actual API:

```php
// Conceptual example only.
$entries[$order] = [
    'mapping' => $orderMapping,
    'state' => 'unchanged',
    'original' => [
        'id' => 10,
        'client_id' => 1,
        'status' => 'pending',
    ],
];
```

The context holds the same PHP object, not a copy. When you change `$order->status`, the context reads that updated value from the object later. The original field values remain stored separately as a snapshot for comparison.

## Comparing Values During Saving

```php
$order->status = 'completed';
$db->saveChanges();
```

`saveChanges()` examines each tracked entity, reads its current field values, and compares them against the original snapshot using strict comparison (`!==`).

| Field | Original Value | Current Value | Result |
| --- | --- | --- | --- |
| id | 10 | 10 | Unchanged |
| client_id | 1 | 1 | Unchanged |
| status | pending | completed | Update |

For a loaded entity, only changed non-primary-key fields are included in the `UPDATE`, for example:

```sql
UPDATE orders SET status = ? WHERE id = ?
```

If there are no differences, no `UPDATE` is performed for that entity. This approach does not require property assignments to notify the context when a value changes.

## After a Successful Save

After writing an entity, the context reloads the row to retrieve database-generated or computed values. Once the save transaction or savepoint completes, it updates the in-memory entity and its snapshot.

Calling `saveChanges()` again without further changes therefore produces no additional writes, provided there are no other pending operations:

```php
$count = $db->saveChanges(); // Returns 0 when no writes are pending.
```

If an exception occurs during saving, the save transaction or savepoint is rolled back, and the snapshots are not marked as saved. If an outer transaction already exists, that transaction remains responsible for the final commit.

## Why Relationship Entities Are Tracked

Orders loaded through `$client->getOrders()` pass through the original context's `materialize()` method. They are therefore registered in the same tracking collection as the client.

This applies to both application-defined `Order` classes and the `DynamicEntity` fallback used when no matching class exists. Changes to their loaded fields can be persisted through the same `$db->saveChanges()` call.

## Understanding the Relationship DbSet

```php
$orders = $client->getOrders();
```

`$orders` is a `PhpEntity\DbSet` object, not a PHP array. It represents a query for this client's orders and holds a reference to the same `DbContext`. Conceptually, its query includes:

```sql
SELECT * FROM orders WHERE client_id = ?
```

You can count results, load entities, or compose additional query conditions:

```php
$orders->count();   // Query the number of matching orders.
$orders->first();   // Load the first matching entity; requires a result.
$orders->toArray(); // Load matching entities into a PHP array.

foreach ($orders as $order) {
    // Each loaded order is tracked by the context.
}
```

Without eager loading, the set stores a query rather than a snapshot of its results. Re-enumerating it executes the query again; the context reuses already tracked entity objects where possible. A relationship preloaded with `include()` instead uses its loaded collection until invalidated; see [Eager Loading](include.md).

## Removing Selected Orders

For orders mapped to an application `Order` class, call `remove()` on the set to register each entity for deletion, then call `saveChanges()` on the same context:

```php
$db->set(Order::class, 'orders'); // Register the application mapping.
$client = $db->set(Client::class)->find(1);

if ($client !== null) {
    $orders = $client->getOrders();

    foreach ($orders as $order) {
        if ($order->status === 'cancelled') {
            $orders->remove($order);
        }
    }

    $db->saveChanges();
}
```

`remove()` registers the deletion; it does not immediately execute SQL. During `saveChanges()`, the context executes a `DELETE` using each removed entity's primary key. Database foreign-key constraints still apply and may prevent deletion.

This deletes the order rows themselves. The set's client filter determines which orders are loaded; it does not provide an additional ownership check when calling `remove()` with an arbitrary object.

### Unlinking an Order Without Deleting It

If the intention is to keep the order but remove its association with the client, clear its foreign key instead, provided the column permits `NULL`:

```php
$order->client_id = null;
$db->saveChanges();
```

This updates the existing order rather than deleting it. For a composite relationship, update the relevant FK columns according to the database constraints.

### Removing an Item from a PHP Array

Changing the array returned by `toArray()` does not register any database deletion:

```php
$items = $client->getOrders()->toArray();
unset($items[0]); // Changes only this PHP array.
```

Use `DbSet::remove()` followed by `saveChanges()` to delete a persisted order.

## Limitations

- Use the same `DbContext` that tracks the object. A new context does not automatically know about changes to objects tracked by another context.
- Creating an object with `new Order()` does not automatically track it. To insert it, register it through `DbSet::add()` or `DbContext::add()`.
- Projections returned by `select()` and raw row results from `asQueryable()` are not tracked as entities.
- Tracked primary keys cannot be changed, and computed fields cannot be modified manually. Attempting to save those changes throws an exception.
- Snapshot comparison uses the values originally loaded by the context. It does not compare against the latest values written by other connections, and does not itself detect concurrent modification conflicts.

Related implementation: [`DbContext`](../src/DbContext.php), [`DbSet`](../src/DbSet.php), and [`EntityMapping`](../src/EntityMapping.php).

## Preloaded Relationships

Relationships loaded with `include()` use the same entity tracking and save
mechanism. Their collection getters return a `DbSet` backed by loaded results,
so reading those results does not issue another query. Additional query operators
create a new database query. Successful saves that perform writes invalidate the
context's relationship caches. See [Eager Loading](include.md) for details.

## Entry Access and Save Hooks

`DbContext::entry()` exposes an `EntityEntry` with the entity, state, original
values, and current values. `changeTracker->entries()` lists tracked entities.
The tracker owns snapshots and identity records; `SaveChangesExecutor` owns SQL
writes and save lifecycle coordination. The context delegates to these components.

The save flow detects changes before `savingChanges()` hooks and again afterwards,
so interceptor edits and state transitions participate in the same save.
See [Entity Entries](entity-entries.md) and [Save Interceptors](save-interceptors.md).
