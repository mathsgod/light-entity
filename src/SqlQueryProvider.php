<?php

declare(strict_types=1);

namespace Light\Entity;

use PDO;
use Light\Linq\IQueryProvider;
use Light\Linq\Expression\Expression;
use Light\Entity\Sql\CompiledQuery;
use Light\Entity\Sql\SqlDialect;
use Light\Entity\Sql\SqlQueryCompiler;

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
        $statement = $this->executeStatement($query);

        return match ($query->resultMode) {
            'count' => (int) $statement->fetchColumn(),
            'boolean' => (bool) $statement->fetchColumn(),
            'firstOrDefault-row', 'firstOrDefault-scalar', 'single-row', 'single-scalar' => $this->terminalResult($statement, $query),
            'any' => (int) $statement->fetchColumn() > 0,
            'first-row' => $this->first($statement->fetch(PDO::FETCH_ASSOC)),
            'first-scalar' => $this->first($statement->fetchColumn()),
            'scalar-list' => $statement->fetchAll(PDO::FETCH_COLUMN),
            default => $statement->fetchAll(PDO::FETCH_ASSOC),
        };
    }

    /** @return list<object>|null null selects the existing hydration path. @internal */
    public function executeNoTrackingEntities(Expression $expression, EntityMapping $mapping): ?array
    {
        if (!$mapping->pdoFetchClassCompatible
            || $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $this->pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)
            || $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)) {
            return null;
        }
        $source = $expression;
        while ($source instanceof \Light\Linq\Expression\MethodCallExpression) {
            if (!in_array($source->method, ['where', 'orderBy', 'thenBy', 'skip', 'take'], true)) {
                return null;
            }
            $source = $source->source;
        }
        if (!$source instanceof \Light\Linq\Expression\SourceExpression || $source->name !== $mapping->table) {
            return null; // Raw SQL may change value types; retain schema conversions.
        }
        $query = $this->compile($expression);
        if ($query->resultMode !== 'rows') {
            return null;
        }
        $statement = $this->executeStatement($query);
        $names = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $names[] = $statement->getColumnMeta($i)['name'] ?? null;
        }
        // Explicit mappings may omit table columns. Preserve validation and ignore extras.
        if (count($names) !== count($mapping->properties) || array_diff($mapping->properties, $names)) {
            $entities = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $entities[] = $mapping->hydrate($row);
            }
            return $entities;
        }
        try {
            $class = $mapping->class;
            $entities = $statement->fetchAll(PDO::FETCH_FUNC, static function (...$values) use ($names, $class): Entity {
                // Eligibility checks above exclude constructors and custom setters.
                $entity = new $class();
                $entity->initializeFromRow(array_combine($names, $values));
                return $entity;
            });
            if ($mapping->booleanProperties !== []) {
                foreach ($entities as $entity) {
                    foreach ($mapping->booleanProperties as $name) {
                        $value = $entity->$name;
                        $entity->$name = $value === null ? null : (bool) $value;
                    }
                }
            }
            return $entities;
        } finally {
            $statement->closeCursor();
        }
    }

    private function executeStatement(CompiledQuery $query): \PDOStatement
    {
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

        return $statement;
    }

    private function terminalResult(\PDOStatement $statement, CompiledQuery $query): mixed
    {
        try {
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                if (str_starts_with($query->resultMode, 'firstOrDefault')) return $query->defaultValue;
                throw new \UnderflowException('The sequence contains no matching element.');
            }
            if (str_starts_with($query->resultMode, 'single') && $statement->fetch(PDO::FETCH_ASSOC) !== false) {
                throw new \UnexpectedValueException('The sequence contains more than one matching element.');
            }
            return str_ends_with($query->resultMode, '-scalar') ? $row['__linq_value'] : $row;
        } finally {
            $statement->closeCursor();
        }
    }
    private function first(mixed $value): mixed
    {
        if ($value === false) {
            throw new \UnderflowException('The query contains no elements.');
        }
        return $value;
    }
}
