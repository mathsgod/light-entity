# Query Tracking Behavior

Entity queries track their results by default. Use `asNoTracking()` for reads whose returned objects do not need automatic change tracking:

```php
$users = $db->set(User::class)
    ->asNoTracking()
    ->toArray();
```

NoTracking materializes entities without consulting the context's identity records, creating original-value snapshots, or registering them for automatic saving. Entity hydration, schema discovery, and SQL execution still occur.

```php
$user = $db->set(User::class)->asNoTracking()->find(1);

if ($user !== null) {
    $user->name = 'Local edit';
    $db->saveChanges(); // Does not persist this object's edit.
}
```

A detached query result is still a mutable PHP object. NoTracking is a query optimization, not a write-protection or authorization mechanism.

## Context Default

Set the default on the context's change tracker:

```php
use Light\Entity\QueryTrackingBehavior;

$db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::NoTracking;

$users = $db->set(User::class)->toArray(); // Untracked.

$db->changeTracker->queryTrackingBehavior = QueryTrackingBehavior::TrackAll;
```

A query without an explicit override resolves this default when it starts materializing entities, not when `set()` is called. That resolved mode applies throughout the execution, even if the default changes while iterating results.

Changing the default does not detach existing tracked entities or discard their changes. They remain eligible for `saveChanges()`.

## Per-Query Overrides

```php
// Track this query even if the context default is NoTracking.
$users = $db->set(User::class)->asTracking()->toArray();

// Do not track this query even if the context default is TrackAll.
$users = $db->set(User::class)->asNoTracking()->toArray();
```

Both methods return new `DbSet` instances. They preserve filters, ordering, pagination, and include paths. The last explicit tracking override wins, and composing one query does not change the original set:

```php
$users = $db->set(User::class);
$readQuery = $users->asNoTracking()->orderBy(Expr::field('name'));
$writeQuery = $readQuery->asTracking();
```

The mode applies to `first()`, `find()`, `toArray()`, and iteration. A NoTracking `find()` bypasses tracked identity lookup and reads the database. It can therefore return an object distinct from an already tracked object with the same key, using database values rather than that tracked object's pending edits.

`count()`, `any()`, projections from `select()`, and raw queries from `asQueryable()` do not materialize tracked entities in either mode.

## Includes and Relationship Getters

Include paths inherit the query's mode at every level:

```php
$client = $db->set(Client::class)
    ->asNoTracking()
    ->include('Orders.Notes')
    ->find(1);
```

The client, orders, and notes remain untracked. Loaded collections still use the include cache, so getters can read preloaded results without additional queries.

For entities extending `Entity`, lazy getters inherit the source object's materialization mode as well:

```php
$client = $db->set(Client::class)->asNoTracking()->find(1);

if ($client !== null) {
    $orders = $client->getOrders()->toArray(); // Also untracked.
}
```

Later changes to the context default do not change this source mode. A tracked source's getters continue to load tracked entities; an untracked source's getters continue to load untracked entities. Explicitly attaching an entity through an entry state transition changes its future getter mode to tracking.

A relationship query can explicitly override its source mode:

```php
$trackedOrders = $client->getOrders()->asTracking()->toArray();
```

When an override changes the mode of a preloaded collection, its cached list is discarded for the new query. SQL is executed to materialize results in the requested mode, rather than returning objects with the previous mode. The original collection is unchanged. Include cache entries are also scoped to their loading mode.

These rules apply to both application entity classes and `DynamicEntity`. Dynamic rows retain only enough mapping metadata to support explicit entry access or writes; this does not register tracking snapshots or tracked state.

## Explicit Writes and Attachment

NoTracking does not disable explicit write operations:

- `add()` still registers an entity for insertion.
- `remove()` still registers an entity for deletion, even when called on a NoTracking set.
- Setting an entry's state to `Added`, `Unchanged`, `Modified`, or `Deleted` explicitly attaches the entity with that state.

For example, explicitly persist edits to a previously untracked object:

```php
use Light\Entity\EntityState;

$user = $db->set(User::class)->asNoTracking()->find(1);

if ($user !== null) {
    $user->name = 'Mary';
    $db->entry($user)->state = EntityState::Modified;
    $db->saveChanges();
}
```

`Modified` forces all mapped non-key, non-computed fields to be updated. To use snapshot-based change detection instead, attach as `Unchanged` before making edits. There is no original database snapshot for edits made while the object was untracked.

Attaching an object conflicts if another object with the same mapped identity is already tracked. Querying with `asTracking()` returns or materializes a tracked result; it does not attach a previously returned NoTracking object or transfer its local edits.

Interceptors see only tracked entries unless they explicitly attach additional objects. Detached NoTracking results do not appear in `changeTracker->entries()` and do not participate in automatic timestamp or soft-delete processing.

## Identity and Performance

NoTracking does not create a context or query-wide identity map. Repeated reads may produce different objects for the same row. Batched includes may share a materialized object among parents in a matching group, but object identity across a graph or between queries is not guaranteed. `NoTrackingWithIdentityResolution` is not implemented.

Skipping identity scans, snapshots, and tracked-record retention reduces tracking overhead, particularly for large read-only result sets. It does not reduce SQL query count or guarantee a particular speedup. The database query, hydration, include buffering, and relationship caches still have their normal costs.

See [Entity Entries](entity-entries.md), [Eager Loading](include.md), and [Save Interceptors](save-interceptors.md).
