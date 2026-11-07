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
