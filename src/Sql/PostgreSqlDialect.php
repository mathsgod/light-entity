<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

final class PostgreSqlDialect extends AbstractSqlDialect
{
    protected function quoteIdentifierPart(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    protected function pagination(?int $limit, ?int $offset, bool $hasOrderBy): array
    {
        $suffix = $limit === null ? '' : ' LIMIT '.$limit;
        if ($offset !== null) {
            $suffix .= ' OFFSET '.$offset;
        }
        return ['prefix' => '', 'suffix' => $suffix];
    }
}
