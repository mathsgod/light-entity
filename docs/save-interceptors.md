# SaveChanges Interceptors

Extend `Light\Entity\SaveChangesInterceptor` to run shared logic around a context's `saveChanges()` operation. Override only the synchronous hooks you need:

```php
use Light\Entity\DbContext;
use Light\Entity\EntityState;
use Light\Entity\SaveChangesInterceptor;

final class OrderAuditInterceptor extends SaveChangesInterceptor
{
    public function savingChanges(DbContext $context): void
    {
        $now = gmdate('Y-m-d H:i:s');

        foreach ($context->changeTracker->entries(Order::class) as $entry) {
            if ($entry->state === EntityState::Added) {
                $entry->entity->created_at = $now;
            } elseif ($entry->state === EntityState::Modified) {
                $entry->entity->updated_at = $now;
            } elseif ($entry->state === EntityState::Deleted) {
                $entry->state = EntityState::Modified;
                $entry->entity->deleted_at = $now;
            }
        }
    }

    public function savedChanges(DbContext $context, int $result): void
    {
        // $result counts entity write operations.
        // Generated keys and refreshed snapshots are now available.
    }

    public function saveChangesFailed(DbContext $context, \Throwable $exception): void
    {
        // Observe the original save error after rollback has been attempted.
    }
}
```

The example assumes `Order` maps the timestamp fields shown. The soft-delete branch changes `Deleted` to `Modified`, replacing the physical delete with an update. Explicitly assigning `Modified` forces writable fields to be updated. Computed columns and primary keys are excluded from that forced update.

Soft deletion does not automatically filter deleted rows from queries. Add your own query conditions when you want to exclude them.

## Registration

Register interceptors per context, either after construction or through the optional constructor argument:

```php
$db->addInterceptors(new OrderAuditInterceptor());

// Alternatively:
$db = new DbContext($pdo, interceptors: [new OrderAuditInterceptor()]);
```

Multiple interceptors run in registration order for each phase. Do not register the same instance twice unless you intend it to run twice. Interceptors with mutable state should normally be created per context.

## Save Lifecycle

1. Detect changes so tracked entry states reflect current values.
2. Begin a local transaction, or create a savepoint inside a transaction owned by `DatabaseFacade`.
3. Invoke `savingChanges()` in registration order.
4. Detect changes again, including entities added or edited by hooks.
5. Validate the write plan, then execute inserts, updates, and deletes.
6. Reload written entities' database values and validate hydration before completing the save transaction/savepoint.
7. Accept snapshots, detach deleted entities, and invalidate relationship caches if entity writes occurred.
8. Invoke `savedChanges()` with the entity write-operation count.

Hooks run even when there are no pending entity writes. A pre-save hook can add entities, change values, detach entities, or change their states for the same save. It should not call `saveChanges()` recursively or register additional interceptors during a save; these operations throw `LogicException`.

`savingChanges()` runs inside the save transaction/savepoint. Immediate SQL issued through the same connection is covered by that transaction. Hooks must not manually commit, roll back, or replace the transaction managed by the save operation.

Changes made in `savedChanges()` are pending changes for a later save. This hook does not trigger another save automatically.

## Failure Semantics

If change detection, transaction setup, a pre-save hook, validation, SQL execution, hydration, or transaction completion fails:

- The executor attempts to roll back only its save transaction or savepoint, if it successfully started one.
- Snapshots are not accepted and generated keys from rolled-back inserts are not copied into entities.
- `saveChangesFailed()` is called on each registered interceptor with the original exception.
- The original exception is rethrown. Exceptions from failure observers are ignored so they do not replace the original failure or prevent the remaining observers from running.

Object changes made before the failure, including pre-save hook changes, remain in memory. Correct the cause and retry; the tracker retains the pending changes.

An exception from `savedChanges()` propagates to the caller after the save has succeeded. It does not trigger `saveChangesFailed()` or roll back the successful write. At that point snapshots have already been accepted; blindly retrying an external side effect in a saved hook requires application-level care.

## Outer Transactions

When using `database->beginTransaction()`, a successful save releases its savepoint but leaves the outer transaction open. `savedChanges()` therefore means the save succeeded, not that the outer transaction committed.

```php
$db->database->beginTransaction();
try {
    $db->saveChanges(); // savedChanges runs while the outer transaction is open.
    $db->database->commitTransaction();
} catch (\Throwable $error) {
    if ($db->database->inTransaction()) {
        $db->database->rollbackTransaction();
    }
    throw $error;
}
```

A later outer rollback does not automatically restore already accepted tracker snapshots. Use a fresh context to reload database state after that rollback. Do not treat `savedChanges()` as an after-commit notification for external messages.

## Scope

This API provides synchronous lifecycle hooks and controlled entry state changes. It does not implement EF Core's interception result/suppression types, async hooks, `PropertyEntry`, automatic audit storage, or an outbox system. To reject a save, throw an exception from `savingChanges()`.

See [Entity Entries](entity-entries.md) for entry properties and state transitions.

## NoTracking Queries

NoTracking results do not appear in `changeTracker->entries()` and do not receive
automatic per-entry processing from hooks. Explicit attachment or write registration
still participates in saving. Changing the query default does not stop previously
tracked entities from being saved. See [Query Tracking](query-tracking.md).
