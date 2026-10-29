<?php

declare(strict_types=1);

namespace PhpEntity;

use PDO;

/** Database operations sharing the DbContext connection. */
final class DatabaseFacade
{
    private bool $ownsTransaction = false;
    private int $savepointIndex = 0;

    public function __construct(private readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getDbConnection(): PDO
    {
        return $this->pdo;
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function beginTransaction(): void
    {
        if ($this->ownsTransaction || $this->pdo->inTransaction()) {
            throw new \LogicException('A transaction is already active.');
        }
        $this->pdo->beginTransaction();
        $this->ownsTransaction = true;
    }

    public function commitTransaction(): void
    {
        $this->requireOwnedTransaction();
        $this->pdo->commit();
        $this->ownsTransaction = false;
    }

    public function rollbackTransaction(): void
    {
        $this->requireOwnedTransaction();
        $this->pdo->rollBack();
        $this->ownsTransaction = false;
    }

    /**
     * Executes parameterized SQL immediately and returns the driver's affected-row count.
     * Does not refresh tracked entities or start an implicit transaction.
     *
     * @param list<scalar|null>|array<string, scalar|null> $parameters
     */
    public function executeSql(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                is_string($value), is_float($value) => PDO::PARAM_STR,
                default => throw new \InvalidArgumentException('SQL parameters must be scalar or null.'),
            };
            $parameter = is_int($name) ? $name + 1 : ':'.ltrim($name, ':');
            $statement->bindValue($parameter, $value, $type);
        }
        $statement->execute();
        return $statement->rowCount();
    }

    /** @internal Starts a local transaction, or a savepoint in the facade transaction. */
    public function beginSaveChanges(): ?string
    {
        if ($this->ownsTransaction) {
            $this->requireOwnedTransaction();
            if (!in_array($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['sqlite', 'mysql', 'pgsql'], true)) {
                throw new \LogicException('SaveChanges savepoints are not supported for this database driver.');
            }
            $savepoint = 'php_entity_save_'.++$this->savepointIndex;
            $this->pdo->exec('SAVEPOINT '.$savepoint);
            return $savepoint;
        }
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('saveChanges() manages its own transaction; use database->beginTransaction() to share one.');
        }
        $this->pdo->beginTransaction();
        return null;
    }

    /** @internal Completes a save without committing the outer facade transaction. */
    public function completeSaveChanges(?string $savepoint): void
    {
        if ($savepoint === null) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        }
    }

    /** @internal Rolls back only the failed save when using a facade transaction. */
    public function rollbackSaveChanges(?string $savepoint): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }
        if ($savepoint === null) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
            $this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        }
    }

    private function requireOwnedTransaction(): void
    {
        if (!$this->ownsTransaction || !$this->pdo->inTransaction()) {
            throw new \LogicException('No active transaction started by this DatabaseFacade.');
        }
    }
}
