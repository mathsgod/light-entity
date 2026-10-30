<?php

declare(strict_types=1);

namespace PhpEntity\Sql;

final class SqlServerDialect extends AbstractSqlDialect
{
    protected function quoteIdentifierPart(string $identifier): string
    {
        return '['.str_replace(']', ']]', $identifier).']';
    }

    protected function pagination(?int $limit, ?int $offset, bool $hasOrderBy): array
    {
        if ($offset === null) {
            return [
                'prefix' => $limit === null ? '' : 'TOP ('.$limit.') ',
                'suffix' => '',
            ];
        }

        $suffix = ' OFFSET '.$offset.' ROWS';
        if ($limit !== null) {
            $suffix .= ' FETCH NEXT '.$limit.' ROWS ONLY';
        }
        return ['prefix' => '', 'suffix' => $suffix];
    }
}
