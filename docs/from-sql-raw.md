# Composable Raw SQL with fromSqlRaw()

Use `fromSqlRaw()` on a root entity set to start a query from a custom SELECT, then apply normal query operators:

```php
use PhpLinq\Expr;

$blogs = $db->set(Blog::class)
    ->fromSqlRaw('SELECT * FROM blogs')
    ->orderBy(Expr::field('name'))
    ->toArray();
```

The compiler wraps the SELECT as a derived table, conceptually:

```sql
SELECT *
FROM (
    SELECT * FROM blogs
) AS raw_source
ORDER BY name
```

The raw SQL is not executed immediately. Filtering, ordering, pagination, projections, aggregates, and materialization are performed through the normal query provider.

## Parameters

Use positional placeholders with a list:

```php
$blogs = $db->set(Blog::class)
    ->fromSqlRaw('SELECT * FROM blogs WHERE owner_id = ?', [$ownerId])
    ->where(Expr::eq(Expr::field('active'), true))
    ->orderBy(Expr::field('name'))
    ->take(10)
    ->toArray();
```

Alternatively, use named placeholders and a map:

```php
$blogs = $db->set(Blog::class)
    ->fromSqlRaw(
        'SELECT * FROM blogs WHERE owner_id = :id OR id = :id',
        ['id' => $ownerId],
    )
    ->toArray();
```

Keys with an optional leading colon, such as `[':id' => $ownerId]`, are accepted. Repeated named placeholders are rewritten to distinct generated parameters for compatibility with native PDO prepared statements. Do not mix positional and named styles in one source. Missing, unused, excess, duplicate-normalized, or invalid parameter keys are rejected. Values must be scalar or null.

Raw source parameters and generated outer-query parameters share one numbering sequence. They cannot collide, including when joining multiple raw sources. Quoted values, quoted identifiers, and comments are lexed before placeholder replacement; a marker appearing in a string or ordinary comment is not a parameter.

SQL text is supplied by the application. Pass user-controlled values through the parameter array rather than interpolating them into SQL. Values cannot stand in for identifiers or SQL clauses; identifiers in the raw string remain the caller's responsibility.

## Root Sets and Query Composition

Apply `fromSqlRaw()` before filters, ordering, or pagination. Tracking options and include paths may be specified before or after it:

```php
$query = $db->set(Blog::class)
    ->asNoTracking()
    ->include('Notes')
    ->fromSqlRaw('SELECT * FROM blogs WHERE owner_id = ?', [$ownerId])
    ->orderBy(Expr::field('name'));
```

The original set is unchanged. Calling `fromSqlRaw()` on a filtered set, a relationship set, or an existing raw SQL query throws `LogicException` rather than silently discarding its conditions.

A raw source is not an unrestricted root identity lookup. `find()` is therefore unavailable on it, preventing a tracked object from bypassing the raw SQL's filters. Use a predicate with `first()` or `where()` and `take(1)` instead:

```php
$blog = $db->set(Blog::class)
    ->fromSqlRaw('SELECT * FROM blogs WHERE owner_id = ?', [$ownerId])
    ->first(Expr::eq(Expr::field('id'), $blogId));
```

`first()` throws `UnderflowException` when there is no result. To obtain a nullable result, read `take(1)->toArray()[0] ?? null`.

Outer expressions refer to the output column names of the raw SELECT. Do not qualify them with the original inner table name. Existing query-composition restrictions, such as `where()` after pagination, still apply.

## Entity Materialization and Tracking

When returning entities, the raw SELECT must include every mapped column under its mapped name, including primary keys and computed columns. Missing columns throw `LogicException`, even if an entity with the same key is already tracked. Null-valued columns are present columns, so they are handled normally.

Mapped columns are converted through the normal schema metadata. Extra result columns are ignored when materializing an entity; use a projection or `asQueryable()` to retrieve them. Result column names must be unambiguous.

Tracking behavior is unchanged:

- Tracking queries reuse objects with the same mapped identity without overwriting their pending edits.
- `asNoTracking()` bypasses identity lookup and snapshots.
- Context tracking defaults and explicit overrides are preserved.
- `include()` loads relationships based on the entity mapping and returned keys, using the query's tracking mode.
- `saveChanges()` updates or deletes the mapping's original table, not the raw SELECT or its derived-table alias.

Return keys and field values that accurately represent the mapped entity rows when those entities will be saved.

## Projections and Joins

Projections can read a subset of columns without materializing an entity:

```php
$names = $db->set(Blog::class)
    ->fromSqlRaw('SELECT name FROM blogs WHERE owner_id = ?', [$ownerId])
    ->select(Expr::field('name'))
    ->toArray();
```

Named projections and raw rows are also available and remain untracked:

```php
$rows = $db->set(Blog::class)
    ->fromSqlRaw('SELECT id, name AS title FROM blogs')
    ->select(Expr::projection(['id' => 'id', 'title' => 'title']))
    ->toArray();
```

Raw sources can join ordinary sets or other raw sources from the same context. Existing join key and projection conventions apply, and parameters from each source are independently renamed into the shared parameter namespace.

## Supported SQL and Limits

The first version accepts a single composable statement starting with `SELECT` after optional whitespace or ordinary comments. Nested SELECTs and compound SELECTs such as `UNION ALL` are supported where the database permits them inside a derived table.

The compiler rejects statement separators, including a trailing semicolon, SELECT INTO, unmatched parentheses, unterminated quotes/comments, and non-SELECT starts. CTE sources starting with `WITH`, stored-procedure calls, and DML/DDL are not supported through this API. Use the existing `DatabaseFacade` APIs for explicit immediate writes.

Lexing recognizes standard doubled quotes and comments, common dialect-specific quoting, PostgreSQL casts/dollar-quoted values/nested comments, and default MySQL backslash escapes and hash comments. MySQL executable comments are rejected. Non-default string-escaping SQL modes may require using bound values instead of raw string literals. Outside quotes and comments, `?` is reserved for positional parameters; use equivalent SQL functions instead of dialect operators that use that character.

Raw SQL must still satisfy the database's own derived-table rules. For example, SQL Server may reject an inner ORDER BY without TOP/OFFSET. The database remains responsible for full syntax and semantic validation. Put ordering in the outer `orderBy()` when possible.

This is a SQL-only source expression; the in-memory provider does not evaluate it.

See [Query Tracking](query-tracking.md), [Eager Loading](include.md), and [Scalar SQL Functions](sql-functions.md).
