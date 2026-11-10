# LINQ 1.3 implementation

light-entity requires `mathsgod/light-linq:^1.3`. The lock file is ignored for this
library; consumers resolve the constraint through Composer. DbSet implements the
expanded `IQueryable` contract. An exposed method does not imply SQL support.

## SQL and entity execution

| LINQ 1.3 change | Implementation |
| --- | --- |
| Closure ordering, grouping, aggregate selectors and join keys | DbSet forwards closures to Queryable translation; existing SQL expression validation applies. |
| Closure terminal predicates | DbSet translates predicates with entity mapping and value conversion before SQL execution; loaded collections use the in-memory translator. |
| `longCount()` | Uses the existing count path; predicate overload filters before counting. PHP integer limits apply. |
| DbSet `elementAt()` / `elementAtOrDefault()` | Enumerates the entity query with existing pagination, includes, identity resolution and tracking. Out-of-range access throws `OutOfBoundsException` or returns the supplied default. This is not an SQL offset optimization. |
| `rightJoin()` | Uses native `RIGHT JOIN` with the original `outer` and `inner` aliases and existing join projection/composition rules. The database must support RIGHT JOIN (SQLite 3.39+). Unmatched outer fields are null. |
| `order()` / `orderDescending()` | Orders a scalar `select()` by its selected expression. Entity rows and named row projections require an explicit `orderBy()`. |
| `reverse()` | Reverses every explicit ordering direction. A paginated input is wrapped in a derived table before reversal, so the selected page remains the same. Projected results must retain the ordering keys. Unordered SQL sources are rejected. |
| Nonpositive `skip()` / `take()` | LINQ normalizes counts to zero; SQL ignores zero skip and compiles zero take as an empty sequence. |
| `asQueryable()` | Preserves the query provider and expression; it does not switch SQL queries to in-memory execution. |

For example:

```php
// SQL ordering of a scalar projection.
$ages = $db->set(User::class)->select(fn ($user) => $user->age)
    ->orderDescending()->toArray();

// Reverse the first three ordered results, rather than select the last three.
$page = $db->set(User::class)->orderBy(fn ($user) => $user->id)
    ->take(3)->reverse()->toArray();
```

`reverse()` returns a Queryable of rows or projected values. Use
`$set->asEnumerable()->reverse()` when entity materialization and tracking are
needed. DbSet ordering methods continue to return DbSet.

## Explicit client execution

The SQL compiler rejects `append`, `prepend`, `defaultIfEmpty`, `skipWhile`,
`takeWhile`, `skipLast`, `takeLast`, `distinctBy`, `exceptBy`, `intersectBy`,
`unionBy`, `countBy`, `aggregate`, `aggregateBy`, `minBy` and `maxBy`. It also
continues to reject `groupJoin`, `zip` and `chunk`. Rejection occurs during
compilation, before a SQL statement executes, including when these operations
appear inside a larger query. Call `asEnumerable()` before these operators:

```php
$counts = $db->set(User::class)->where(fn ($user) => $user->active == 1)
    ->asEnumerable()->countBy(fn ($user) => $user->status)->toArray();

$total = $db->set(User::class)->asEnumerable()
    ->aggregate(fn ($sum, $user) => $sum + $user->age, 0);
```

This keeps filtering in SQL and runs subsequent operations on materialized
entities. A previously loaded DbSet can already use the in-memory aggregate
path; an unloaded SQL query never automatically falls back to PHP.

Queryable `elementAt()` and `elementAtOrDefault()` on projections are not yet
translated by the SQL provider; use `asEnumerable()` for these. The DbSet
entity overloads described above work without this conversion.

Indexed callbacks emit `IndexExpression`, which has no SQL translation here.
Use `asEnumerable()` before indexed `select`, `where`, `selectMany`, `skipWhile`
or `takeWhile`. Closure collection selectors in Queryable `selectMany` are
likewise not SQL collection sources; the existing explicit IQueryable overload
remains supported.

Enumerable-only additions (`cast`, `ofType`, `tryGetNonEnumeratedCount`, numeric
sequence generators, result selectors and nullable averages) are supplied by
light-linq. Use `asEnumerable()` to access them. SQL `average()` retains database
aggregate semantics, including null on an empty sequence. PHP closures and SQL
can differ in null comparison, collation and numeric behavior.

## Validation

Compiler tests cover SQLite, MySQL, PostgreSQL and SQL Server generation for
right joins, scalar ordering, reversal across pagination, and explicit rejection
of unsupported operators. Entity tests cover closure contracts, terminal value
conversion, pagination and element access; they require `pdo_sqlite`.
