# Changelog

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
