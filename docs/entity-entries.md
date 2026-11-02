# Entity Entries and Change Tracking

`DbContext::entry()` exposes a live view of an entity's tracking information. The API uses EF Core-style names while preserving PHP-ENTITY's snapshot-based tracking.

```php
use PhpEntity\EntityState;

$order = $db->set(Order::class)->find(10);

if ($order !== null) {
    $entry = $db->entry($order);

    $entry->entity;         // The same $order object.
    $entry->context;        // The owning DbContext.
    $entry->metadata;       // Its immutable EntityMapping.
    $entry->state;          // EntityState enum.
    $entry->originalValues; // Read-only array copy of the snapshot.
    $entry->currentValues;  // Read-only array copy of mapped current values.

    $order->status = 'completed';

    assert($entry->state === EntityState::Modified);
    $db->saveChanges();
    assert($entry->state === EntityState::Unchanged);
}
```

Entries read the tracker's current record rather than storing their own snapshot copies. An entry obtained before an edit or save continues to reflect that entity's tracking information afterwards.

## Entity States

`EntityState` is a string-backed enum:

| State | Meaning |
| --- | --- |
| `Detached` | Not tracked; it will not be saved. |
| `Unchanged` | Tracked with no detected changes or forced update. |
| `Added` | Registered for insertion. |
| `Modified` | Has mapped field changes or has been explicitly marked for update. |
| `Deleted` | Registered for deletion. |

For ordinary tracked entities, reading `state` compares current values with the original snapshot. Restoring all fields to their original values returns the state to `Unchanged`, unless an update was explicitly forced. Non-mapped fields do not affect tracking.

## Enumerating Entries

```php
foreach ($db->changeTracker->entries() as $entry) {
    echo $entry->state->value;
}

foreach ($db->changeTracker->entries(Order::class) as $entry) {
    // Only matching tracked entities, including subclasses.
}

$db->changeTracker->detectChanges();
```

`entries()` detects changes and returns a stable list. It is safe to detach entries or change their states while iterating. Detached entities are not included.

## Changing State

State assignments go through validation; the tracker does not expose its mutable storage:

```php
$entry->state = EntityState::Modified;
```

The transitions have these effects:

- `Added` registers an insert. A generated key must be null, and non-generated key components must be present.
- `Modified` registers an update and forces all mapped, non-key, non-computed fields to be written, even if their values are unchanged. If there are no writable fields, no update is needed.
- `Deleted` registers a delete. For an entity still in `Added`, it cancels the pending insertion and detaches the entity instead.
- `Detached` stops tracking without writing or reverting the object's values.
- `Unchanged` accepts the current values as the tracking baseline without writing them to the database. It does not reload or undo local changes.

Attaching an existing entity as `Unchanged`, `Modified`, or `Deleted` requires a complete primary key. An existing tracked entity cannot change its primary key through a state transition or save. A second object with the same mapped identity cannot be attached while the first is tracked.

```php
$new = new User();
$new->name = 'Mary';

$entry = $db->entry($new);
assert($entry->state === EntityState::Detached); // Entry alone does not attach it.

$entry->state = EntityState::Added;
$db->saveChanges();
// The generated key and database defaults are now available on $new.
```

For an untracked object, `entry()` discovers its mapping but does not query for its row or start tracking it. Until attached, `originalValues` reflects the object's current mapped values because no database snapshot exists. Changing the state of a detached object to attach it uses those current values as its initial snapshot.

## Value Access

`originalValues` and `currentValues` are read-only array copies. Modify the entity itself to change field values:

```php
$before = $entry->originalValues['status'];
$entry->entity->status = 'completed';
$after = $entry->currentValues['status'];
```

Do not assign to these value properties or edit them as nested arrays. There is no `PropertyEntry`, `property()`, per-field `isModified` override, or mutable values container in this version.

## Responsibilities

- `DbContext` provides entity sets, entry access, relationships, and the public save API.
- `ChangeTracker` owns identity records, snapshots, change detection, and state transitions.
- `EntityEntry` exposes a controlled view of one tracked or detached entity.
- `SaveChangesExecutor` coordinates save hooks, SQL, transactions/savepoints, and snapshot acceptance.
- `SaveChangesInterceptor` supplies synchronous save lifecycle hooks.

Existing `add()`, `remove()`, relationship loading, and `include()` use the same tracker. No separate tracking system is created for entries.

See [Change Tracking](change-tracking.md), [Save Interceptors](save-interceptors.md), and [Eager Loading](include.md).

## Query Tracking Defaults

Set `changeTracker->queryTrackingBehavior` to `QueryTrackingBehavior::NoTracking`
to disable automatic tracking for queries by default. Individual sets can use
`asTracking()` or `asNoTracking()` to override it. A NoTracking result's entry
remains `Detached` until explicitly attached. See [Query Tracking](query-tracking.md).
