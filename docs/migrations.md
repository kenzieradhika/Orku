# Experimental SQLite migrations

The initial `Orku\Data\Migrator` supports SQLite only. It never runs at startup
and has no destructive rollback command. Back up real databases first; test the
exact migration on a copy before an explicit operator-controlled deployment.

```php
$runner = new Orku\Data\Migrator($pdo);
$applied = $runner->up([
    '001_users' => 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
    '002_email' => 'ALTER TABLE users ADD COLUMN email TEXT',
]);
```

Pass trusted application SQL, never request input. IDs must be nonempty ASCII
letters/digits/underscore/hyphen, start with a letter/digit, have at most 128 bytes
and be strictly lexically ordered. Use nonnumeric prefixes such as `001_users`
to avoid PHP converting array keys to integers. SQL checksums are immutable after
application; changing an applied entry fails. Applied entries are skipped.

The runner records history in `orku_migrations`, using one transaction per batch.
Schema/history roll back together on supported SQLite statements. Existing caller
transactions are rejected. Run one migration process at a time; SQLite locking
errors are surfaced, never silently retried.

SQL can execute arbitrary database operations. This is not a sandbox or SQL parser.
Do not include BEGIN/COMMIT/ROLLBACK, PRAGMA transaction changes, ATTACH, external
side effects or statements modifying the history table. Such operations can defeat
transaction guarantees. No down migrations, file discovery, cross-driver DDL
guarantees, missing-history detection or production deployment validation yet.
