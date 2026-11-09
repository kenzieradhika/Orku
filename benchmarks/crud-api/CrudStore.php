<?php

declare(strict_types=1);

namespace Orku\CrudBench;

/** Thrown by the shared fixture; maps directly to the API error envelope. */
final class CrudError extends \RuntimeException
{
    private const MESSAGES = [
        400 => 'Bad Request',
        404 => 'Not Found',
        409 => 'Conflict',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Content',
        500 => 'Internal Server Error',
    ];

    public function __construct(public readonly int $status)
    {
        parent::__construct(self::MESSAGES[$status] ?? 'Request Error');
    }

    /** @return array<string, array{status: int, message: string}> */
    public static function envelope(int $status): array
    {
        return ['error' => ['status' => $status, 'message' => self::MESSAGES[$status] ?? 'Request Error']];
    }
}

/**
 * Shared CRUD semantics for the disposable Orku/Laravel benchmark fixtures.
 * Both fixtures call the same class so request behaviour cannot drift.
 */
final class CrudStore
{
    public const MAX_BODY_BYTES = 65536;
    public const SEED_ROWS = 200;
    private const MAX_TITLE = 200;
    private const MAX_BODY = 2000;
    private const MAX_CLIENT_KEY = 64;
    private const MAX_LIST_LIMIT = 100;

    public function __construct(private readonly \PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec((string) file_get_contents(__DIR__.'/schema.sql'));
    }

    public static function connect(string $path): self
    {
        return new self(new \PDO('sqlite:'.$path));
    }

    /** @return array{rows: int} */
    public function reset(): array
    {
        $this->transaction(function (): void {
            $this->pdo->exec('DELETE FROM items');
            try {
                $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'items'");
            } catch (\PDOException) {
                // sqlite_sequence is created on the first AUTOINCREMENT insert.
            }
            $insert = $this->pdo->prepare('INSERT INTO items (title, body, version, client_key) VALUES (?, ?, 1, ?)');
            for ($i = 1; $i <= self::SEED_ROWS; $i++) {
                $insert->execute([sprintf('Seed item %04d', $i), sprintf('Seed body %04d', $i), sprintf('seed-%04d', $i)]);
            }
            $insert->closeCursor();
        });

        return ['rows' => self::SEED_ROWS];
    }

    /** @return array{rows: int, max_version: int, version_sum: int} */
    public function state(): array
    {
        $statement = $this->pdo->query('SELECT COUNT(*) AS rows, COALESCE(MAX(version), 0) AS max_version, COALESCE(SUM(version), 0) AS version_sum FROM items');
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return [
            'rows' => (int) ($row['rows'] ?? 0),
            'max_version' => (int) ($row['max_version'] ?? 0),
            'version_sum' => (int) ($row['version_sum'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, count: int}
     */
    public function list(array $query): array
    {
        $limit = $this->queryInt($query, 'limit', self::MAX_LIST_LIMIT, 1, self::MAX_LIST_LIMIT);
        $offset = $this->queryInt($query, 'offset', 0, 0, PHP_INT_MAX);
        $statement = $this->pdo->prepare('SELECT id, title, body, version, client_key FROM items ORDER BY id LIMIT ? OFFSET ?');
        $statement->execute([$limit, $offset]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return ['data' => array_map($this->row(...), $rows), 'count' => count($rows)];
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->find($this->id($id));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload, bool $failAfterInsert = false): array
    {
        $this->fields($payload, ['title', 'body', 'client_key']);
        $title = $this->field($payload, 'title', self::MAX_TITLE, 1);
        $body = $this->field($payload, 'body', self::MAX_BODY, 0);
        $clientKey = $this->field($payload, 'client_key', self::MAX_CLIENT_KEY, 1);
        if (!preg_match('/^[A-Za-z0-9._:-]+$/D', $clientKey)) {
            throw new CrudError(422);
        }
        try {
            $this->transaction(function () use ($title, $body, $clientKey, $failAfterInsert): void {
                $statement = $this->pdo->prepare('INSERT INTO items (title, body, version, client_key) VALUES (?, ?, 1, ?)');
                try {
                    $statement->execute([$title, $body, $clientKey]);
                } finally {
                    $statement->closeCursor();
                }
                if ($failAfterInsert) {
                    throw new \RuntimeException('simulated failure after insert');
                }
            });
        } catch (\PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new CrudError(409);
            }
            throw $error;
        }
        $id = (string) $this->pdo->lastInsertId();

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function replace(string $id, array $payload): array
    {
        $id = $this->id($id);
        $this->fields($payload, ['title', 'body', 'version']);
        $title = $this->field($payload, 'title', self::MAX_TITLE, 1);
        $body = $this->field($payload, 'body', self::MAX_BODY, 0);
        $version = $this->version($payload);

        return $this->transaction(function () use ($id, $title, $body, $version): array {
            $statement = $this->pdo->prepare('UPDATE items SET title = ?, body = ?, version = version + 1 WHERE id = ? AND version = ?');
            try {
                $statement->execute([$title, $body, $id, $version]);
                $changed = $statement->rowCount();
            } finally {
                $statement->closeCursor();
            }
            if ($changed === 0) {
                $this->conflictOrMissing($id);
            }

            return $this->find($id);
        });
    }

    /** Returns null for the empty 204 response. */
    public function delete(string $id, string $version): ?array
    {
        $id = $this->id($id);
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $version)) {
            throw new CrudError(422);
        }

        $this->transaction(function () use ($id, $version): void {
            $statement = $this->pdo->prepare('DELETE FROM items WHERE id = ? AND version = ?');
            try {
                $statement->execute([$id, (int) $version]);
                $changed = $statement->rowCount();
            } finally {
                $statement->closeCursor();
            }
            if ($changed === 0) {
                $this->conflictOrMissing($id);
            }
        });

        return null;
    }

    /** @template T @param callable(): T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $value = $operation();
            $this->pdo->commit();

            return $value;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT id, title, body, version, client_key FROM items WHERE id = ?');
        $statement->execute([(int) $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if ($row === false) {
            throw new CrudError(404);
        }

        return $this->row($row);
    }

    private function conflictOrMissing(string $id): never
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM items WHERE id = ?');
        $statement->execute([(int) $id]);
        $exists = $statement->fetchColumn();
        $statement->closeCursor();

        throw new CrudError($exists === false ? 404 : 409);
    }

    private function id(string $id): string
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            throw new CrudError(404);
        }

        return $id;
    }

    /** @param array<string, mixed> $payload @param list<string> $expected */
    private function fields(array $payload, array $expected): void
    {
        $keys = array_keys($payload);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new CrudError(422);
        }
    }

    /** @param array<string, mixed> $payload */
    private function field(array $payload, string $name, int $maxBytes, int $minBytes): string
    {
        $value = $payload[$name] ?? null;
        if (!is_string($value) || strlen($value) < $minBytes || strlen($value) > $maxBytes || !preg_match('//u', $value)) {
            throw new CrudError(422);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function version(array $payload): int
    {
        $value = $payload['version'] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new CrudError(422);
        }

        return $value;
    }

    /** @param array<string, mixed> $query */
    private function queryInt(array $query, string $name, int $default, int $min, int $max): int
    {
        foreach (array_keys($query) as $key) {
            if (!in_array($key, ['limit', 'offset'], true)) {
                throw new CrudError(422);
            }
        }
        $value = $query[$name] ?? $default;
        if (is_string($value) && preg_match('/^[0-9]{1,10}$/D', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new CrudError(422);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'version' => (int) $row['version'],
            'client_key' => (string) $row['client_key'],
        ];
    }
}
