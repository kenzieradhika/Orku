<?php

declare(strict_types=1);

$operations = filter_var($argv[1] ?? 200, FILTER_VALIDATE_INT);
$workers = filter_var($argv[2] ?? 8, FILTER_VALIDATE_INT);
$busyTimeoutMs = filter_var($argv[3] ?? 5000, FILTER_VALIDATE_INT);
if ($operations === false || $workers === false || $operations < 1 || $operations > 10000
    || $workers < 1 || $workers > 32 || $operations % $workers !== 0
    || $busyTimeoutMs === false || $busyTimeoutMs < 0 || $busyTimeoutMs > 5000) {
    fwrite(STDERR, "Usage: concurrent_integrity_check.php [operations 1..10000 divisible by workers] [workers 1..32] [busy timeout 0..5000ms]\n");
    exit(2);
}
if (!extension_loaded('pdo_sqlite') || !extension_loaded('pcntl')) {
    fwrite(STDERR, "pdo_sqlite and pcntl required\n");
    exit(2);
}
$path = tempnam(sys_get_temp_dir(), 'orku-integrity-');
if ($path === false) {
    throw new RuntimeException('Could not create temporary database.');
}
$workersPids = [];
$report = null;
try {
    $setup = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $setup->exec('PRAGMA journal_mode=WAL');
    $setup->exec('CREATE TABLE counter (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
    $setup->exec('INSERT INTO counter VALUES (1, 0)');
    $setup->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, worker INTEGER NOT NULL, seq INTEGER NOT NULL, UNIQUE(worker, seq))');
    $setup = null;
    $start = hrtime(true);
    for ($worker = 0; $worker < $workers; ++$worker) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork worker.');
        }
        if ($pid === 0) {
            try {
                $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('PRAGMA busy_timeout='.$busyTimeoutMs);
                for ($seq = 0; $seq < intdiv($operations, $workers); ++$seq) {
                    $pdo->beginTransaction();
                    $pdo->exec('UPDATE counter SET value = value + 1 WHERE id = 1');
                    $insert = $pdo->prepare('INSERT INTO events (worker, seq) VALUES (?, ?)');
                    $insert->execute([$worker, $seq]);
                    $pdo->commit();
                }
                exit(0);
            } catch (Throwable $error) {
                file_put_contents($path.'.worker-'.$worker.'.error', $error::class.' '.$error->getCode());
                exit(1);
            }
        }
        $workersPids[] = $pid;
    }
    $workerFailures = 0;
    foreach ($workersPids as $pid) {
        $waited = pcntl_waitpid($pid, $status);
        if ($waited !== $pid) {
            ++$workerFailures;
            continue;
        }
        $workerFailures += !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0;
    }
    $lockFailures = [];
    foreach (glob($path.'.worker-*.error') ?: [] as $errorFile) {
        $lockFailures[] = ['file' => basename($errorFile), 'error' => file_get_contents($errorFile)];
    }
    $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = (int) $pdo->query('SELECT value FROM counter WHERE id = 1')->fetchColumn();
    $events = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $unique = (int) $pdo->query('SELECT COUNT(DISTINCT worker || ":" || seq) FROM events')->fetchColumn();
    $pdo->beginTransaction();
    $pdo->exec('INSERT INTO events (worker, seq) VALUES (999, 999)');
    $pdo->rollBack();
    $rollback = (int) $pdo->query('SELECT COUNT(*) FROM events WHERE worker = 999')->fetchColumn() === 0;
    $report = ['driver' => 'sqlite', 'workers' => $workers, 'operations_requested' => $operations,
        'busy_timeout_ms' => $busyTimeoutMs,
        'counter_value' => $count, 'event_count' => $events, 'unique_event_count' => $unique,
        'worker_failures' => $workerFailures, 'lock_errors' => $lockFailures,
        'rollback_verified' => $rollback,
        'elapsed_ms' => (hrtime(true) - $start) / 1e6,
        'integrity_passed' => $workerFailures === 0 && $count === $operations
            && $events === $operations && $unique === $operations && $rollback];
    $pdo = null;
} finally {
    foreach ($workersPids as $pid) {
        $result = pcntl_waitpid($pid, $status, WNOHANG);
        if ($result === 0) {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
    }
    foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (glob($path.'.worker-*.error') ?: [] as $file) {
        unlink($file);
    }
}
echo json_encode($report, JSON_THROW_ON_ERROR)."\n";
exit($report['integrity_passed'] ? 0 : 1);
