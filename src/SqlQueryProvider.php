<?php

declare(strict_types=1);

namespace PhpEntity;

use PDO;
use PhpLinq\IQueryProvider;
use PhpLinq\Expression\Expression;
use PhpEntity\Sql\CompiledQuery;
use PhpEntity\Sql\SqlDialect;
use PhpEntity\Sql\SqlQueryCompiler;

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
        foreach ($query->parameters as $name => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                is_string($value), is_float($value) => PDO::PARAM_STR,
                default => throw new \InvalidArgumentException('SQL parameters must be scalar or null.'),
            };
            $statement->bindValue(is_int($name) ? $name + 1 : ':'.ltrim($name, ':'), $value, $type);
        }
        $statement->execute();

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
