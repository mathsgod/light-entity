# Closure translation

`DbSet::where()` and `select()` accept restricted PHP query closures. The
translator reads the closure's PHP source and parses its AST into expression
nodes. The SQL provider then compiles those nodes into SQL with bound values.
It does not invoke the closure for each entity or fetch all rows to filter in PHP.
Query execution remains deferred until enumeration or a terminal operation.

## String matching becomes SQL LIKE

```php
$customers = $db->set(Customers::class)
    ->where(fn($c) => str_starts_with($c->name, 'a'));

$rows = $customers->toArray();
```

Assuming the entity maps to `customers`, the SQL is conceptually:

```sql
SELECT * FROM customers WHERE name LIKE 'a%'
```

The actual SQL uses dialect-specific identifier quoting and bound parameters:

```sql
SELECT * FROM customers WHERE (name LIKE :p0 ESCAPE :p1)
```

Here `p0` is `a%` and `p1` is `!`. Translation proceeds through
`StringMatchExpression` in light-linq, then `Light\Entity\Sql\LikeExpression`.
The source call to `str_starts_with()` is recognized, not executed in PHP.

| PHP query expression | SQL pattern for needle `a` |
| --- | --- |
| `str_starts_with($c->name, 'a')` | `a%` |
| `str_ends_with($c->name, 'a')` | `%a` |
| `str_contains($c->name, 'a')` | `%a%` |

Search strings are literal. A needle of `a%` means the characters `a%`, so its
starts-with pattern is `a!%%` with `ESCAPE '!'`. Literal `%`, `_`, `!`, and `[` are
escaped before wildcards are added. Captured strings and field-valued needles
are supported; dynamic needles are escaped in SQL using `REPLACE`.

```php
$prefix = 'a';
$customers->where(fn($c) => str_starts_with($c->name, $prefix));
$customers->where(fn($c) => str_contains($c->name, $c->search_term));
$customers->where(fn($c) => !str_ends_with($c->name, 'z'));
```

Matching follows database collation and SQL NULL rules, rather than necessarily
PHP's case-sensitive behavior. A NULL operand produces UNKNOWN, which `WHERE`
excludes even when the match is negated. An empty needle matches every non-NULL
string, including an empty string.

## Arithmetic, comparisons, and projections

```php
$db->set(Orders::class)
    ->where(fn($o) => $o->total + $o->tax >= 30)
    ->select(fn($o) => ['id' => $o->id, 'total' => $o->total])
    ->toArray();
```

Arithmetic uses nested `BinaryExpression` nodes and preserves the parsed
precedence and parentheses. SQL arithmetic follows the database's rules,
including integer division and division by zero.

## Supported syntax and boundaries

- Arrow functions or anonymous functions with one ordinary row parameter and a
  single `return` expression. Anonymous functions capture values with `use`.
- Property paths, scalar/null literals, and captured scalar/null variables.
- Scalar `strtolower`, `strtoupper`, `trim`, `strlen`, `abs`, and `round` calls
  with the positional signatures documented below.
- Null coalescing `??` and full ternary `condition ? value : fallback`; shorthand
  `value ?: fallback` is unsupported.
- Arithmetic `+ - * / %`, comparisons `== != > >= < <=`, logical
  `&& || and or`, and negation `!`. Strict identity `===` and `!==` is unsupported.
- `str_starts_with`, `str_ends_with`, and `str_contains`, each with two positional
  arguments. Named and unpacked arguments are unsupported.
- Non-empty projections with explicit string keys, or a single field selection.
- `contains()`, `any()`, and inline `any(fn($row) => ...)` on captured
  `IQueryable` objects using the same provider/context. These become SQL `IN`
  and `EXISTS`; see [the README examples](../README.md#closure-translation).

Arbitrary PHP function/method calls, assignments, loops, and dynamic properties
are unsupported. Build captured subqueries before entering a query closure;
query-building chains inside the closure are not translated.

A readable PHP source file is required; closures created with `eval` are
unsupported. Put closures on separate lines so reflection can locate each one
uniquely. Captured values are read on each translation, not on later enumeration.
Unsupported syntax throws `ClosureTranslationException` during query
construction, with no automatic client-side fallback. SQL planner restrictions
can separately raise `LogicException` during compilation.

For general PHP callbacks, explicitly cross the client boundary:

```php
$db->set(Customers::class)->asEnumerable()
    ->where(fn($c) => customPhpPredicate($c));
```

Install a compatible light-linq version containing arithmetic and
`StringMatchExpression` support. A sibling checkout alone does not update the
installed Composer dependency. For full syntax and provider responsibilities,
see [light-linq's closure guide](https://github.com/mathsgod/light-linq/blob/main/docs/closure-translation.md).

## Coalesce and conditional expressions

```php
$db->set(Orders::class)->select(fn($o) => [
    'customer' => $o->customer_name ?? 'Guest',
    'size' => $o->total >= 100 ? 'large' : 'small',
]);
```

`CoalesceExpression` compiles to `COALESCE(customer_name, :fallback)`;
`ConditionalExpression` compiles to `CASE WHEN total >= :threshold THEN :large
ELSE :small END`. Constants remain bound parameters. Both expressions also work
as scalar selections, inside predicates, and in nested expressions.

`??` falls back only for NULL, preserving zero, false, and empty strings.
Referenced fields must still exist in the row/schema. A NULL conditional test
selects the ELSE branch. Use a supported SQL predicate for the condition and
branch types compatible with the database; PHP scalar truthiness is not a
portable SQL condition. Short ternary `?:` is explicitly rejected.
The in-memory provider evaluates only the selected branch; SQL execution and
optimization follow database rules, so unselected branches are not a portable
way to hide invalid SQL operations such as division by zero.

For explicit trees, use `Expr::coalesce(Expr::field('name'), 'Guest')` or
`Expr::condition(Expr::gte(Expr::field('total'), 100), 'large', 'small')`.

## Scalar functions

```php
$db->set(Customers::class)
    ->where(fn($c) => strtolower(trim($c->name)) == 'alice')
    ->select(fn($c) => ['name' => strtoupper($c->name), 'bytes' => strlen($c->name)]);

$db->set(Orders::class)->select(fn($o) => round(abs($o->balance), 2));
```

The parser creates a `Light\Linq\Expressions\ScalarFunctionExpression` for
each supported call. Its arguments are expressions and may contain fields,
captured constants, other scalar functions, coalesce, or conditional expressions.
These are built-in operations; they do not use `$db->function()` registration or
invoke application PHP functions during SQL translation.

| PHP | MySQL | SQLite | PostgreSQL | SQL Server |
| --- | --- | --- | --- | --- |
| `strtolower($value)` | `LOWER` | `LOWER` | `LOWER` | `LOWER` |
| `strtoupper($value)` | `UPPER` | `UPPER` | `UPPER` | `UPPER` |
| `trim($value)` | `REGEXP_REPLACE` | `TRIM(value, chars)` | `BTRIM(value, chars)` | `TRIM(chars FROM value)` |
| `strlen($value)` | `LENGTH` | `LENGTH(CAST(value AS BLOB))` | `OCTET_LENGTH` | `DATALENGTH` |
| `abs($value)` | `ABS` | `ABS` | `ABS` | `ABS` |
| `round($value[, $precision])` | `ROUND` | `ROUND`, with scaling for negative precision | `ROUND(CAST(value AS NUMERIC), precision)` | `ROUND` |

Each call accepts one positional argument. Only `round` accepts a second:
a constant integer precision, including captured integers; the default is zero.
Custom `trim` masks, explicit rounding modes, named/unpacked arguments, and
field-valued precision fail during closure translation. All functions propagate
NULL. Scalar and named projections, filtering, grouped expressions and explicit
function-valued join keys are supported within the existing planner rules.

### String semantics

`strlen` is a byte-length operation, matching PHP's unit rather than counting
characters. The SQL result measures the database value's storage encoding and
includes trailing spaces. For example, UTF-8 `中` has three bytes. SQL Server
NVARCHAR typically stores UTF-16, so its storage byte count can differ from the
UTF-8 string received by PHP. SQLite uses a BLOB cast to include embedded NULs
and avoid character-count semantics. Use string/text inputs; coercion of other
SQL types is not a portable equivalent of a PHP string argument.

SQL `LOWER`/`UPPER` follow database locale/collation rules; they may transform
Unicode characters that PHP's ASCII `strtolower`/`strtoupper` leave unchanged.
SQLite's standard build also uses ASCII case conversion unless extended.

`trim` removes spaces, tabs, LF, CR and vertical tabs at both ends. MySQL 8 uses
an anchored regex and also includes NUL in its whitespace set. SQLite and SQL
Server leave NUL bytes intact; PostgreSQL text cannot contain NUL. Thus those
cases are not exact equivalents of PHP's default trim set. SQL Server requires
TRIM support (2017 or later); MySQL requires REGEXP_REPLACE (8.0 baseline).
Custom character masks and PHP range syntax such as `a..z` are not translated.

### Numeric semantics

The in-memory provider uses PHP `abs` and `round` with `PHP_ROUND_HALF_UP`.
SQL uses database numeric types and rounding behavior: decimal versus binary
floating point, halfway cases, integer overflow and return types may differ.
Use compatible numeric types; this translation is not a guarantee of identical
financial rounding across providers.

SQLite's native negative precision acts like zero, so the compiler explicitly
scales by `10 ** -precision`, rounds, and scales back. It rejects precisions
below -308 because that factor is not finite. PostgreSQL casts to NUMERIC for
its two-argument ROUND overload. Other database precision limits still apply.

Primary references: [PHP strlen](https://www.php.net/manual/en/function.strlen.php),
[PHP trim](https://www.php.net/manual/en/function.trim.php),
[PHP round](https://www.php.net/manual/en/function.round.php),
[SQLite scalar functions](https://www.sqlite.org/lang_corefunc.html),
[MySQL string functions](https://dev.mysql.com/doc/refman/8.0/en/string-functions.html),
[PostgreSQL mathematical functions](https://www.postgresql.org/docs/current/functions-math.html),
and [SQL Server DATALENGTH](https://learn.microsoft.com/en-us/sql/t-sql/functions/datalength-transact-sql).
