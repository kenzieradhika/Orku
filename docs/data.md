# Optional PDO foundation

`Orku\Data\Database` wraps an application-created PDO connection. No database
driver or connection is loaded by the HTTP kernel. Install PDO and the driver
only when using this optional module. SQLite is the currently tested driver.

```php
$db = new Orku\Data\Database(new PDO('sqlite::memory:'));
$rows = $db->query('SELECT name FROM users WHERE id = ?', [42]);
$db->transaction(function (Orku\Data\Database $db): void {
    $db->execute('UPDATE users SET name = ? WHERE id = ?', ['Ada', 42]);
});
```

SQL is trusted application code. Bind values through positional or named
parameters; identifiers, ordering clauses and SQL fragments cannot be bound.
Allowlist those separately. The wrapper enables PDO exception mode and closes
cursors in finally. Query returns all rows; use application-specific bounded
queries, not unbounded exports. Driver parameter coercion remains PDO behavior.

Transactions commit on success and roll back on exceptions. Nested transactions
are rejected. DDL may implicitly commit on some drivers; do not rely on rollback
for such statements. A rollback failure is surfaced with the original operation
exception as its cause. No automatic retries, query logs, ORM, migrations,
profiling, connection pool or cross-driver guarantees are implemented.

This is an optional namespace in the existing Composer package; splitting a
standalone package is deferred until distribution requires it.
