<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

final class MySqlDialect extends AbstractSqlDialect
{
    protected function quoteIdentifierPart(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    protected function pagination(?int $limit, ?int $offset, bool $hasOrderBy): array
    {
        if ($limit === null && $offset === null) {
            return ['prefix' => '', 'suffix' => ''];
        }
        $limitSql = $limit === null ? '18446744073709551615' : (string) $limit;
        $suffix = ' LIMIT '.$limitSql;
        if ($offset !== null) {
            $suffix .= ' OFFSET '.$offset;
        }
        return ['prefix' => '', 'suffix' => $suffix];
    }
}
