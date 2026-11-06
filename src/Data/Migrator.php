<?php

declare(strict_types=1);

namespace Orku\Data;

final class Migrator
{
    public function __construct(private readonly \PDO $pdo)
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \InvalidArgumentException('Initial migrator supports SQLite only.');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param array<array-key, string> $migrations Trusted, ordered migration IDs and schema statements.
     * @return list<string>
     */
    public function up(array $migrations): array
    {
        $previous = '';
        foreach ($migrations as $id => $sql) {
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D', $id) || strcmp($id, $previous) <= 0 || trim($sql) === '') {
                throw new \InvalidArgumentException('Migration IDs must be valid, unique and lexically ordered; SQL must not be empty.');
            }
            $previous = $id;
        }
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Migrations require their own transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS orku_migrations (id TEXT PRIMARY KEY, checksum TEXT NOT NULL)');
            $lookup = $this->pdo->prepare('SELECT checksum FROM orku_migrations WHERE id = ?');
            $record = $this->pdo->prepare('INSERT INTO orku_migrations (id, checksum) VALUES (?, ?)');
            $applied = [];
            foreach ($migrations as $id => $sql) {
                $checksum = hash('sha256', $sql);
                $lookup->execute([$id]);
                $existing = $lookup->fetchColumn();
                $lookup->closeCursor();
                if ($existing !== false) {
                    if ($existing !== $checksum) {
                        throw new \LogicException('Applied migration checksum changed: '.$id);
                    }
                    continue;
                }
                $this->pdo->exec($sql);
                $this->requireTransaction();
                $record->execute([$id, $checksum]);
                $record->closeCursor();
                $applied[] = $id;
            }
            $this->pdo->commit();

            return $applied;
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
                throw new \RuntimeException('Migration rollback failed.', previous: $error);
            }
        }
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Migration ended its transaction.');
        }
    }
}
