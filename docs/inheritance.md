# Entity inheritance (TPH)

Entity classes that inherit from another application `Entity` class use
**table per hierarchy (TPH)** by default. The entire hierarchy shares the table
of its highest application base class, directly below `Light\Entity\Entity`.
A standalone entity with no known derived types keeps its existing mapping.

```php
use Light\Entity\Entity;

class Animal extends Entity {}
class Cat extends Animal {}
class Dog extends Animal {}
```

These classes share `animals`; they do not require `cats` or `dogs` tables.
The root's `#[Table('...')]` attribute or an explicit table argument can name the
shared table. Derived types cannot use a different table within the hierarchy.

## Schema and discriminator

light-entity is database-first: create or migrate the shared table yourself.
There is no automatic schema change. By default, it requires a writable string
column named `Discriminator`, with the short PHP class name as its value:

```sql
CREATE TABLE animals (
    id INTEGER PRIMARY KEY,
    name TEXT,
    Discriminator TEXT NOT NULL,
    lives INTEGER,
    favorite_toy TEXT
);

INSERT INTO animals VALUES (1, 'Mimi', 'Cat', 9, NULL);
INSERT INTO animals VALUES (2, 'Lucky', 'Dog', NULL, 'ball');
```

This DDL is for SQLite. For MySQL use an `AUTO_INCREMENT` primary key and an
appropriate string type such as `VARCHAR(100)` for the discriminator. Fields
used only by one subtype should allow null for other types.

```php
$cats = $db->set(Cat::class)->toArray();
// SELECT * FROM animals WHERE Discriminator = :p0   (:p0 = 'Cat')
// Every result is a Cat (or a further subclass of Cat).

$animals = $db->set(Animal::class)->toArray();
// SELECT * FROM animals
// Materializes Cat and Dog objects according to each row's discriminator.
```

Further subclasses are included in a base-type query. For example, a `Kitten`
subclass of `Cat` adds its discriminator to the Cat query. Abstract entity roots
are supported and have no discriminator value of their own. Unknown, missing,
or incompatible discriminator values fail materialization explicitly.

Already loaded subclasses are discovered when the hierarchy is first mapped.
For Composer-autoloaded types that may not yet be loaded, register them in
`onModelCreating()` with `$builder->entity(Cat::class)` and
`$builder->entity(Dog::class)`. The ORM does not scan source files. Load/register
derived types before discovering the root mapping; use `hasDiscriminator()`
up front if the root must be configured before its subtypes are requested.

## Custom discriminator values

Configure the root in `onModelCreating()`:

```php
protected function onModelCreating(\Light\Entity\ModelBuilder $builder): void
{
    $builder->entity(Animal::class)
        ->hasDiscriminator('animal_type')
        ->hasValue(Animal::class, 'animal')
        ->hasValue(Cat::class, 'cat')
        ->hasValue(Dog::class, 'dog');
}
```

Create `animal_type` in the database instead of `Discriminator` for this model.
Discriminator values are non-empty strings and must be unique within the
hierarchy. Set explicit values when different namespaces contain the same short
class name, or to keep database values stable across PHP class renames. Choose
values that are distinct under the database column's collation as well.

## Tracking and writes

The root and derived sets share identity tracking by hierarchy and primary key:

```php
$cat = $db->set(Cat::class)->find(1);
assert($cat === $db->set(Animal::class)->find(1));

$newCat = new Cat();
$newCat->name = 'New cat';
$db->set(Animal::class)->add($newCat);
$db->saveChanges(); // Inserts into animals with Discriminator = 'Cat'.
```

The runtime class selects the discriminator for inserts and the concrete
tracking metadata, even when adding through a base set. Updates and deletes use
the same table. The discriminator cannot be changed to convert an existing
entity to another type; bulk updates cannot assign it either.

Type restrictions apply to `find`, aggregates, projections, raw SQL, joins,
subqueries, relationship queries and bulk writes. `ignoreQueryFilters()` only
removes configured query filters; it never removes the TPH type predicate.
Root query filters and relationship overrides are inherited by subtypes.
Relationships to a table with several mapped TPH types infer the hierarchy root;
pass an explicit `targetClass` to restrict a relationship to a particular type.

No-tracking and streamed reads also materialize the concrete type. The MySQL
single-class native hydration optimization is disabled for TPH mappings so it
cannot instantiate every row as the base class.

## Compatibility and limits

This changes the previous convention in which inherited classes independently
mapped to pluralized table names. Existing applications using separate tables
must migrate their data into the shared table and populate the discriminator,
or supply explicit `EntityMapping` objects to retain independent mappings.
Explicit mappings remain independent; do not mix them with inferred TPH mappings of the same hierarchy.

This convention change requires a major release under the project's compatibility
policy. TPT and TPC inheritance strategies, automatic class-file discovery,
non-string discriminators, and type changes of existing rows are not supported.
