<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

final class SqliteDialect extends AbstractSqlDialect
{
    protected function quoteIdentifierPart(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    protected function pagination(?int $limit, ?int $offset, bool $hasOrderBy): array
    {
        if ($limit === null && $offset === null) {
            return ['prefix' => '', 'suffix' => ''];
        }
        $suffix = ' LIMIT '.($limit ?? -1);
        if ($offset !== null) {
            $suffix .= ' OFFSET '.$offset;
        }
        return ['prefix' => '', 'suffix' => $suffix];
    }
    protected function functionName(SqlFunctionDefinition $function): string
    {
        if ($function->schema !== null) {
            throw new \LogicException('SQLite scalar functions cannot be schema-qualified.');
        }
        return parent::functionName($function);
    }
    protected function parameterSql(string $placeholder, mixed $value): string
    {
        // PDO has no float binding type. SQLite expression results have no column
        // affinity to coerce a string-bound floating-point comparison operand.
        return is_float($value) ? 'CAST('.$placeholder.' AS REAL)' : $placeholder;
    }
}
