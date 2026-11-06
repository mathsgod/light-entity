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
