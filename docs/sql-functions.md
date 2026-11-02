# Scalar SQL Functions

Call an installed database scalar function directly as an expression; registration is optional:

```php
use Light\Linq\Expr;

$score = $db->function('GetCustomScore', Expr::field('user_id'));

$topUsers = $db->set(User::class)
    ->where(Expr::gt($score, 80))
    ->orderByDescending($score)
    ->toArray();
```

The function is called in SQL by the database, not by iterating entities in PHP. The generated query is conceptually:

```sql
SELECT * FROM users
WHERE GetCustomScore(user_id) > :p0
ORDER BY GetCustomScore(user_id) DESC
```

The dialect quotes the function name and identifiers. `80` is bound as a parameter. Entity results follow the query's normal tracking mode, including `asNoTracking()` and `include()` behavior.

## Optional Registration

```php
$db->registerFunction(
    'GetCustomScore',
    schema: 'dbo',
    parameterCount: 1,
);
```

Register a function when you want a shared schema qualifier or early argument-count validation. Registration stores context-local metadata. It does not create a database function, inspect the database for its signature, or execute it. Install the function through your database setup or migrations first.

- `name` is the function's actual database name.
- `schema` is optional. SQL Server and PostgreSQL use it as a schema; MySQL uses the qualifier as a database name. SQLite scalar functions cannot be schema-qualified and compilation rejects a non-null schema.
- `parameterCount` is optional. When provided, calls must have exactly that many arguments, including zero for a parameterless function. When omitted, the database validates the argument count.

Names and schemas must be simple identifiers matching `[A-Za-z_][A-Za-z0-9_]*`. Pass the schema separately rather than embedding a dot in the name. Registration lookup is case-insensitive; the registered spelling is preserved in SQL. Registering the same name again throws `LogicException`, even if a different schema is specified.

Without registration, `$db->function()` validates the name and creates an unqualified call using the supplied spelling. The database checks whether the function exists and whether its arguments are valid. Direct calls do not implicitly register mappings. Once a mapping is registered, subsequent calls use its schema, registered spelling, and declared argument count; an incorrect declared argument count throws `InvalidArgumentException` before SQL execution. Existing expression objects keep the definition they were created with.

This API targets scalar database functions callable with quoted identifiers. It does not translate engine-specific built-in syntax or provide a portable catalog of built-in functions. The installed function and its naming conventions must match the selected database engine.

## Arguments and Parameter Binding

Arguments can be value expressions or scalar/null constants:

```php
$score = $db->function('GetCustomScore', Expr::field('user_id'));
$adjusted = $db->function('AddBonus', $score, 5);
$echo = $db->function('EchoValue', "A quoted 'value'");
$nullable = $db->function('EchoValue', null);
$constant = $db->function('CurrentScore');
```

Nested calls remain SQL expressions. Constants are bound as parameters; they are not inserted into SQL text. Use `Expr::field()` for column references: passing `'user_id'` directly means the literal string, not a column name.

Integer, boolean, and null parameters use corresponding PDO binding types. Floating-point parameters use string binding because PDO has no float binding type; SQLite emits `CAST(:parameter AS REAL)` to preserve numeric comparisons against expression results.

SQL function argument types, return types, null propagation, and execution errors are governed by the database function. This version does not register PHP return-type conversions or null-propagation metadata.

## Projections

Select a scalar function result:

```php
$scores = $db->set(User::class)
    ->orderBy(Expr::field('user_id'))
    ->select($db->function('GetCustomScore', Expr::field('user_id')))
    ->toArray();

$firstScore = $db->set(User::class)
    ->orderBy(Expr::field('user_id'))
    ->select($db->function('GetCustomScore', Expr::field('user_id')))
    ->first();
```

A scalar selection returns values directly, including null values. `first()` still throws `UnderflowException` if there are no rows. Returned PHP types follow PDO and the database's result types.

For a named projection, combine fields and function expressions:

```php
$rows = $db->set(User::class)
    ->select(Expr::projection([
        'id' => 'user_id',
        'score' => $db->function('GetCustomScore', Expr::field('user_id')),
    ]))
    ->toArray();
```

Projections remain untracked. The same function expression may appear in filtering, ordering, and projection; SQL may contain multiple calls. The ORM does not guarantee a single evaluation or automatically create a computed alias for reuse.

Function expressions can also be used in join keys. Fields inside their arguments are qualified relative to the corresponding join source, following the existing join API. A scalar function selection cannot be used as a row join source; use a named projection when joining projected data.

## Context Wrappers

A custom context can provide a named PHP method that builds the expression:

```php
use PDO;
use Light\Entity\DbContext;
use Light\Linq\Expression\ValueExpression;

class MyDbContext extends DbContext
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->registerFunction('GetCustomScore', parameterCount: 1);
    }

    public function getCustomScore(ValueExpression $userId): ValueExpression
    {
        return $this->function('GetCustomScore', $userId);
    }
}
```

Then use the wrapper in queries:

```php
$topUsers = $db->set(User::class)
    ->where(Expr::gt($db->getCustomScore(Expr::field('user_id')), 80))
    ->toArray();
```

The method returns an expression; it does not return the actual score or throw as an EF Core expression-tree placeholder would. No PHP closure parsing or attribute-based function discovery is involved.

## Scope

Supported: scalar calls in predicates, ordering, scalar/named projections, and join keys, including nested calls and bound constants. Existing query-composition restrictions still apply.

Not provided: table-valued functions, stored-procedure execution through this API, arbitrary SQL fragments, automatic function creation, client-side evaluation, or automatic translation of ordinary PHP function calls.

`FunctionExpression` implements the existing php-linq `ValueExpression` interface but is SQL-specific. The in-memory query provider rejects it rather than attempting to invoke a PHP function.
