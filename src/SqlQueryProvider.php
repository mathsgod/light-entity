<?php

declare(strict_types=1);

namespace PhpEntity;

use PDO;
use PhpLinq\IQueryProvider;
use PhpLinq\Expression\Expression;
use PhpLinq\Sql\CompiledQuery;
use PhpLinq\Sql\SqlDialect;
use PhpLinq\Sql\SqlQueryCompiler;

final readonly class SqlQueryProvider implements IQueryProvider
{
    private SqlQueryCompiler $compiler;

    public function __construct(private PDO $pdo, SqlDialect $dialect)
    {
        $this->compiler = new SqlQueryCompiler($dialect);
    }

    public function compile(Expression $expression): CompiledQuery
    {
        return $this->compiler->compile($expression);
    }

    public function execute(Expression $expression): mixed
    {
        $query = $this->compile($expression);
        $statement = $this->pdo->prepare($query->sql);
        $statement->execute($query->parameters);

        return match ($query->resultMode) {
            'count' => (int) $statement->fetchColumn(),
            'any' => (int) $statement->fetchColumn() > 0,
            'first-row' => $this->first($statement->fetch(PDO::FETCH_ASSOC)),
            'first-scalar' => $this->first($statement->fetchColumn()),
            'scalar-list' => $statement->fetchAll(PDO::FETCH_COLUMN),
            default => $statement->fetchAll(PDO::FETCH_ASSOC),
        };
    }

    private function first(mixed $value): mixed
    {
        if ($value === false) {
            throw new \UnderflowException('The query contains no elements.');
        }
        return $value;
    }
}
