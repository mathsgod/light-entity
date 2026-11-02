# Array filters

`DbSet::where()` accepts either an existing `ValueExpression` or a filter array:

```php
$result = $context->set(User::class)
    ->where([
        'age' => ['gte' => 18],
        'status' => 'Active',
        '_or' => [
            ['role' => ['eq' => 'Admin']],
            ['role' => ['eq' => 'Manager']],
        ],
    ])
    ->toArray();
```

Fields at the same level are combined with AND. Multiple `where()` calls also
combine with AND, and arrays can be mixed with existing `Expr` predicates.
Use mapped field names. Logical names are case-sensitive.

Supported field operators: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, and `in`.
A scalar or null is shorthand for `eq`. Multiple operators on the same field
are combined with AND: `'age' => ['gte' => 18, 'lt' => 65]`.
`eq: null` means IS NULL; `neq: null` means IS NOT NULL. Other comparisons
reject null. `in` accepts a list and compiles to grouped equality comparisons;
a null member therefore matches IS NULL.

`_and` and `_or` accept lists of filter arrays. `_not` accepts a single filter
array and applies SQL NOT (including SQL's three-valued NULL semantics).
Groups can nest. An empty filter or `_and` is true; an empty `_or` or `in` is
false. Malformed filters and unknown operators throw `InvalidArgumentException`.
All operand values are bound as SQL parameters.

For GraphQL, define corresponding input types in your application's schema:

```graphql
input IntFilter {
    eq: Int
    neq: Int
    gt: Int
    gte: Int
    lt: Int
    lte: Int
    in: [Int]
}

input UserWhereInput {
    age: IntFilter
    _and: [UserWhereInput!]
    _or: [UserWhereInput!]
    _not: UserWhereInput
}
```

```graphql
query {
    listUser(where: {
        _or: [
            { age: { lte: 18 } },
            { age: { gte: 65 } }
        ]
    }) {
        name
    }
}
```

Pass the resolver's associative `where` array to `DbSet::where()`. This library
does not create a GraphQL endpoint or schema. GraphQL fields with filter input
types require operator objects, while PHP additionally supports scalar shorthand.
In public APIs, expose only the fields callers are authorized to filter.
