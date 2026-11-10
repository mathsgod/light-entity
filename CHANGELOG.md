# Changelog

## v1.3.0 — 2026-11-10

### Complex properties

- Add opt-in `complexProperty()` configuration for required single-level value
  objects flattened into the owner's table, with inferred/explicit types and
  custom leaf column names.
- Assemble complex objects during hydration, flatten them for writes and scalar
  snapshots, detect in-place member changes, and preserve defaults/computed values.
- Bind member paths in filters, ordering, projection, aggregates, join keys,
  correlated subqueries and bulk assignments with source-specific metadata.
- Support no-tracking, Include and inherited TPH complex metadata, with explicit
  rejection of conflicting columns, nullable/nested/readonly object shapes and
  whole-object SQL projections/comparisons.
- Add SQLite, dialect compilation and MySQL integration coverage and document
  the initial feature scope in `docs/complex-properties.md`.

### Entity inheritance (TPH)

- Infer table-per-hierarchy mappings for inherited Entity classes, using the
  root table and a string `Discriminator` column by default. This ships in
  v1.3.0 as an explicit compatibility-policy exception: the previous inherited
  entity convention had not been used in current applications. Code relying on
  separate inherited tables requires migration or explicit EntityMapping objects.
- Materialize runtime subtypes for root queries and share tracking identity
  across the hierarchy. Preserve concrete types during no-tracking and includes.
- Apply structural type predicates to derived queries, joins, subqueries, raw
  SQL, includes and bulk writes, even when query filters are ignored.
- Populate discriminator values on insert; reject discriminator changes and
  unknown types. Inherit root query filters and relationship configuration.
- Add `hasDiscriminator()` / `hasValue()` configuration for custom string
  discriminator columns and values, including abstract roots.
- Document schema requirements, type discovery and migration in
  `docs/inheritance.md`.

### LINQ compatibility

- Require `mathsgod/light-linq:^1.3` and adapt DbSet to its expanded IQueryable
  contract, including closure-compatible selectors and predicates.
- Forward new LINQ query operators without silently evaluating unsupported SQL
  operations in PHP. DbSet element access enumerates the existing entity query,
  preserving pagination, materialization and tracking.
- Treat zero skip as a SQL no-op, including normalized negative skip counts.
- Compile right joins, scalar identity ordering, and explicitly ordered reverse
  queries, preserving pagination through a derived table.
- Reject unsupported LINQ 1.3 SQL operators explicitly with an `asEnumerable()`
  boundary, and document the implementation and provider support matrix.

## v1.2.0 — 2026-11-06

- Require stable mathsgod/light-linq ^1.2.
- Compile arithmetic and full conditional/coalesce expressions to SQL, including
  scalar result shapes, grouped expressions and bound parameters.
- Compile literal string matching with native LikeExpression nodes and escaped
  patterns, including dynamic field-valued needles.
- Compile supported string/numeric scalar functions with dialect-specific byte
  lengths, trim behavior, ROUND signatures and negative SQLite precision.
- Support bound raw SQL ordering and IN subqueries across contexts sharing a
  connection, including the changes from the 1.2 release candidates.
- Avoid deprecated SplObjectStorage methods on PHP 8.5.
- Document closure translation and provider semantic boundaries.
