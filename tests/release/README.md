# Disposable SQLite check

Run only against a newly-created temporary file. It creates 100 synthetic rows,
checks point lookups, bounded lists, repeated PDO open/close, transaction commit
and rollback, then removes its database. It does not invoke Orku migrations or
touch an application database. SQLite/PDO results do not establish support for
other database drivers.

Bounded sample (1..1,000 iterations, 30-second PHP fixture timeout):

```sh
python3 tests/release/database_check.py --iterations 100 \
  --output /tmp/opencode/database-sample.json
```

Raw per-operation timings and summaries are written only to the chosen output.
The runner uses `/tmp/opencode` when available; never provide a DB path because
the fixture owns and deletes its temporary DB. If interrupted, Python's
temporary-directory cleanup removes the disposable directory when the process
exits normally. SIGKILL/power loss can leave only that uniquely named temp
directory; remove it manually after verifying it belongs to this run.

Current fixture exercises direct PDO because Laravel database integration is
not present and the objective is to validate SQLite semantics first. This does
not compare framework DB integration or claim performance parity.

## Concurrent integrity fixture

`concurrent_integrity_check.php` uses a new temporary SQLite database, bounded
local `pcntl_fork` workers, atomic counter updates, unique event rows, and
rollback verification. Example:

```sh
php tests/release/concurrent_integrity_check.php 200 8
```

Limits: 10,000 operations, 32 workers, operation count divisible by worker
count. It removes only its own temporary DB/WAL files. SQLite lock behavior is
reported as worker failure, not silently retried as success. This does not test
web-session isolation or cross-session CSRF; authentication is not implemented.
Set the third argument to vary `busy_timeout_ms` from 0 to 5,000; lock errors
are returned as `lock_errors`. Test coverage repeats 320 operations at 1/4/16/32
workers. Results indicate SQLite's behavior under this fixture, not a framework
concurrency guarantee.
