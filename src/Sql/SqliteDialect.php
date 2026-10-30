<?php

declare(strict_types=1);

namespace PhpEntity\Sql;

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
}
