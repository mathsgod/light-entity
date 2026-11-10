<?php

declare(strict_types=1);

namespace Light\Entity;

use PDO;
use Light\Linq\IQueryProvider;
use Light\Linq\Expressions\Expression;
use Light\Entity\Sql\CompiledQuery;
use Light\Entity\Sql\SqlDialect;
use Light\Entity\Sql\SqlQueryCompiler;

final readonly class SqlQueryProvider implements IQueryProvider
{
    private SqlQueryCompiler $compiler;
    private ?\Closure $prepareQuery;

    public function __construct(private PDO $pdo, SqlDialect $dialect, ?callable $prepareQuery = null)
    {
        $this->compiler = new SqlQueryCompiler($dialect);
        $this->prepareQuery = $prepareQuery === null ? null : \Closure::fromCallable($prepareQuery);
    }

    /** SQL composition is safe only when both providers share the exact PDO connection. */
    public function sharesConnectionWith(self $other): bool
    {
        return $this->pdo === $other->pdo;
    }

    public function compile(Expression $expression): CompiledQuery
    {
        return $this->compiler->compile($this->prepare($expression));
    }

    public function execute(Expression $expression): mixed
    {
        $query = $this->compile($expression);
        $statement = $this->executeStatement($query);

        return match ($query->resultMode) {
            'sum' => $this->sumResult($statement),
            'average', 'min', 'max' => $this->aggregateResult($statement, $query->resultMode),
            'count' => (int) $statement->fetchColumn(),
            'boolean' => (bool) $statement->fetchColumn(),
            'firstOrDefault-row', 'firstOrDefault-scalar', 'single-row', 'single-scalar',
            'singleOrDefault-row', 'singleOrDefault-scalar', 'last-row', 'last-scalar',
            'lastOrDefault-row', 'lastOrDefault-scalar' => $this->terminalResult($statement, $query),
            'any' => (int) $statement->fetchColumn() > 0,
            'first-row' => $this->first($statement->fetch(PDO::FETCH_ASSOC)),
            'first-scalar' => $this->first($statement->fetchColumn()),
            'scalar-list' => $statement->fetchAll(PDO::FETCH_COLUMN),
            default => $statement->fetchAll(PDO::FETCH_ASSOC),
        };
    }

    /** @internal null values selects DELETE. */
    public function executeModification(Expression $expression, ?array $values = null): int
    {
        $statement = $this->executeStatement($this->compiler->compileModification($this->prepare($expression), $values));
        try {
            return $statement->rowCount();
        } finally {
            $statement->closeCursor();
        }
    }

    /** Streams entity rows without buffering the result in PHP. @internal
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamRows(Expression $expression): \Generator
    {
        $query = $this->compile($expression);
        if ($query->resultMode !== 'rows') {
            throw new \LogicException('Entity streaming requires a row query.');
        }
        // Buffering is selected when the statement executes. Restore the connection
        // option before yielding; the active result itself remains unbuffered.
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $bufferAttribute = $mysql ? (PHP_VERSION_ID >= 80400 ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;
        $buffered = $mysql ? $this->pdo->getAttribute($bufferAttribute) : null;
        try {
            if ($mysql) { $this->pdo->setAttribute($bufferAttribute, false); }
            $statement = $this->executeStatement($query);
        } finally {
            if ($mysql) { $this->pdo->setAttribute($bufferAttribute, $buffered); }
        }
        try {
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                yield $row;
            }
        } finally {
            $statement->closeCursor();
        }
    }

    /** @return list<object>|null null selects the existing hydration path. @internal */
    public function executeNoTrackingEntities(Expression $expression, EntityMapping $mapping): ?array
    {
        if ($mapping->hierarchy !== null || !$mapping->pdoFetchClassCompatible
            || $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $this->pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)
            || $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)) {
            return null;
        }
        $expression = $this->prepare($expression);
        $source = $expression;
        while ($source instanceof \Light\Linq\Expressions\MethodCallExpression) {
            if (!in_array($source->method, ['where', 'orderBy', 'thenBy', 'skip', 'take'], true)) {
                return null;
            }
            $source = $source->source;
        }
        if (!$source instanceof \Light\Linq\Expressions\SourceExpression || $source->name !== $mapping->table) {
            return null; // Raw SQL may change value types; retain schema conversions.
        }
        $query = $this->compiler->compile($expression);
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
            $reflection = new \ReflectionClass($class);
            $hasConstructor = $reflection->getConstructor() !== null;
            $converters = $mapping->converters;
            $entities = $statement->fetchAll(PDO::FETCH_FUNC, static function (...$values) use ($names, $class, $reflection, $hasConstructor, $converters): Entity {
                // Match ordinary hydration: constructors never run; custom setters use fallback.
                $entity = $hasConstructor ? $reflection->newInstanceWithoutConstructor() : new $class();
                $row = array_combine($names, $values);
                foreach ($converters as $name => $converter) {
                    $row[$name] = $converter->convertFromProvider($row[$name]);
                }
                $entity->initializeFromRow($row);
                return $entity;
            });
            if ($mapping->booleanProperties !== []) {
                foreach ($entities as $entity) {
                    foreach ($mapping->booleanProperties as $name) {
                        if (isset($converters[$name])) { continue; }
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

    private function prepare(Expression $expression): Expression
    {
        return $this->prepareQuery === null ? $expression : ($this->prepareQuery)($expression);
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

    private function aggregateResult(\PDOStatement $statement, string $method): mixed
    {
        try {
            $row = $statement->fetch(PDO::FETCH_NUM);
            if ($row === false) throw new \UnexpectedValueException('Aggregate query returned no row.');
            $value = $row[0];
            if ($method === 'average' && $value !== null && !is_int($value) && !is_float($value)
                && !(is_string($value) && is_numeric($value))) {
                throw new \UnexpectedValueException('AVG must return a numeric value or null.');
            }
            return $value;
        } finally {
            $statement->closeCursor();
        }
    }

    private function sumResult(\PDOStatement $statement): int|float|string
    {
        try {
            $value = $statement->fetchColumn();
            if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                throw new \UnexpectedValueException('SUM must return a numeric value.');
            }
            return $value;
        } finally {
            $statement->closeCursor();
        }
    }

    private function terminalResult(\PDOStatement $statement, CompiledQuery $query): mixed
    {
        try {
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                if (str_contains($query->resultMode, 'OrDefault-')) return $query->defaultValue;
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
