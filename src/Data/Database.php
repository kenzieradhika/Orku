<?php

declare(strict_types=1);

namespace Orku\Data;

final class Database
{
    public function __construct(private readonly \PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /** @param array<int|string, bool|float|int|string|null> $parameters */
    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        try {
            $statement->execute($parameters);

            return $statement->rowCount();
        } finally {
            $statement->closeCursor();
        }
    }

    /**
     * @param array<int|string, bool|float|int|string|null> $parameters
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        try {
            $statement->execute($parameters);

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

            return $rows;
        } finally {
            $statement->closeCursor();
        }
    }

    /**
     * @template T
     * @param callable(self): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Nested transactions are not supported.');
        }
        $this->pdo->beginTransaction();
        try {
            $value = $operation($this);
            $this->pdo->commit();

            return $value;
        } catch (\Throwable $error) {
            $this->rollback($error);
            throw $error;
        }
    }

    private function rollback(\Throwable $error): void
    {
        if ($this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (\Throwable) {
                throw new \RuntimeException('Transaction rollback failed.', previous: $error);
            }
        }
    }
}
