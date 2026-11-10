<?php

declare(strict_types=1);

$path = $argv[1] ?? '';
$iterations = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
if ($path === '' || $iterations === false || $iterations < 1 || $iterations > 1000) {
    fwrite(STDERR, "Invalid fixture arguments.\n");
    exit(2);
}
if (!is_file(dirname(__DIR__, 2).'/vendor/autoload.php')) {
    fwrite(STDERR, "Composer autoloader required.\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = new Orku\Data\Database($pdo);
$pdo->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
$insert = $pdo->prepare('INSERT INTO records (id, value) VALUES (?, ?)');
for ($i = 1; $i <= 100; ++$i) {
    $insert->execute([$i, 'synthetic-'.$i]);
}
$select = $pdo->prepare('SELECT value FROM records WHERE id = ?');
$rows = [];
$measure = static function (string $case, callable $operation) use (&$rows): void {
    $start = hrtime(true);
    try {
        $operation();
        $ok = true;
    } catch (Throwable $error) {
        $ok = false;
    }
    $rows[] = ['case' => $case, 'duration_ms' => (hrtime(true) - $start) / 1e6, 'ok' => $ok];
};
for ($i = 0; $i < $iterations; ++$i) {
    $measure('point_lookup_pdo', static function () use ($select, $i): void {
        $select->execute([($i % 100) + 1]);
        if ($select->fetchColumn() === false) {
            throw new RuntimeException('Missing seeded row');
        }
        $select->closeCursor();
    });
    $measure('point_lookup_orku', static function () use ($db, $i): void {
        if (count($db->query('SELECT value FROM records WHERE id = ?', [($i % 100) + 1])) !== 1) {
            throw new RuntimeException('Orku point lookup mismatch');
        }
    });
    $measure('bounded_list_pdo', static function () use ($pdo): void {
        if (count($pdo->query('SELECT id FROM records ORDER BY id LIMIT 20')->fetchAll(PDO::FETCH_COLUMN)) !== 20) {
            throw new RuntimeException('List result mismatch');
        }
    });
    $measure('bounded_list_orku', static function () use ($db): void {
        if (count($db->query('SELECT id FROM records ORDER BY id LIMIT 20')) !== 20) {
            throw new RuntimeException('Orku list result mismatch');
        }
    });
    $measure('transaction_cycle_orku', static function () use ($db, $i): void {
        $inserted = $db->transaction(static fn (Orku\Data\Database $database): int => $database->execute(
            'INSERT INTO records (value) VALUES (?)',
            ['txn-'.$i],
        ));
        if ($inserted !== 1) {
            throw new RuntimeException('Orku transaction insert count mismatch');
        }
        if ($db->execute('DELETE FROM records WHERE value = ?', ['txn-'.$i]) !== 1) {
            throw new RuntimeException('Orku transaction cleanup mismatch');
        }
    });
    $measure('connection_open_close', static function () use ($path): void {
        $connection = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->query('SELECT 1')->fetchColumn();
        $connection = null;
    });
}
$before = (int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn();
try {
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO records (value) VALUES ('rollback-marker')");
    $pdo->rollBack();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
$rollbackVerified = (int) $pdo->query("SELECT COUNT(*) FROM records WHERE value = 'rollback-marker'")->fetchColumn() === 0;
$pdo->beginTransaction();
$pdo->exec("INSERT INTO records (value) VALUES ('commit-marker')");
$pdo->commit();
$commitVerified = (int) $pdo->query("SELECT COUNT(*) FROM records WHERE value = 'commit-marker'")->fetchColumn() === 1;
$sqliteVersion = $pdo->query('SELECT sqlite_version()')->fetchColumn();
$pdo = null;
unset($select, $insert);
if (!unlink($path)) {
    throw new RuntimeException('Could not remove disposable SQLite file.');
}
if ($before !== 100 || !$rollbackVerified || !$commitVerified) {
    throw new RuntimeException('Database integrity check failed.');
}
echo json_encode([
    'driver' => 'sqlite',
    'php_version' => PHP_VERSION,
    'sqlite_version' => $sqliteVersion,
    'seed_rows' => $before,
    'rollback_verified' => $rollbackVerified, 'commit_verified' => $commitVerified,
    'operations' => $rows,
    'summary' => summarizeRows($rows),
    'errors' => count(array_filter($rows, static fn (array $row): bool => !$row['ok'])),
], JSON_THROW_ON_ERROR);

function summarizeRows(array $rows): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['case']][] = $row['duration_ms'];
    }
    $summary = [];
    foreach ($grouped as $case => $durations) {
        sort($durations);
        $summary[$case] = ['operations' => count($durations),
            'p50_ms' => $durations[(int) ceil(.50 * count($durations)) - 1],
            'p95_ms' => $durations[(int) ceil(.95 * count($durations)) - 1],
            'p99_ms' => $durations[(int) ceil(.99 * count($durations)) - 1]];
    }
    return $summary;
}
