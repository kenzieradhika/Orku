<?php

declare(strict_types=1);

foreach (['WebException.php', 'TrustedHtml.php', 'ViewContext.php', 'ViewRenderer.php'] as $file) {
    require dirname(__DIR__, 2).'/src/Web/'.$file;
}

use Orku\Web\TrustedHtml;
use Orku\Web\ViewRenderer;

$root = sys_get_temp_dir().'/orku-ssr-'.bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) {
    throw new RuntimeException('Could not create SSR fixture directory.');
}
$status = 0;
try {
    file_put_contents($root.'/static.php', '<p>Release validation</p>');
    file_put_contents($root.'/value.php', '<p><?= $view->escape($view->get("value")) ?></p>');
    file_put_contents($root.'/nested.php', '<main><?= $view->raw($view->slot("content")) ?></main>');
    $views = new ViewRenderer([$root]);
    $cases = [
        'static' => $views->render('static'),
        'utf8' => $views->render('value', ['value' => 'café']),
        'null' => $views->render('value', ['value' => null]),
        'empty' => $views->render('value', ['value' => '']),
        'nested' => $views->render('nested', slots: ['content' => TrustedHtml::fromTrustedString('<p>inner</p>')]),
        'escaped' => $views->render('value', ['value' => '<script>alert(1)</script>']),
        'raw_unsafe' => $views->render('nested', slots: ['content' => TrustedHtml::fromTrustedString('<script>marker</script>')]),
        'raw_unsafe_expected' => '<main><script>marker</script></main>',
    ];

    if (($argv[1] ?? '') === '--cases') {
        echo json_encode($cases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } else {
        $count = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if (($argv[1] ?? '') !== '--render' || $count === false) {
            fwrite(STDERR, "usage: ssr_check.php --cases | --render 1..1000\n");
            $status = 2;
        } else {
            for ($i = 0; $i < 100; ++$i) {
                $views->render('value', ['value' => 'warm']);
            }

            $timerStart = hrtime(true);
            for ($i = 0; $i < 1000; ++$i) {
                $emptyStart = hrtime(true);
                hrtime(true) - $emptyStart;
            }
            $timerOverheadNs = (hrtime(true) - $timerStart) / 1000;
            $latencies = [];
            $hash = null;
            $elapsed = 0;
            for ($i = 0; $i < $count; ++$i) {
                $start = hrtime(true);
                $html = $views->render('value', ['value' => 'benchmark']);
                $duration = hrtime(true) - $start;
                $elapsed += $duration;
                $latencies[] = max(0, $duration - $timerOverheadNs);
                $hash = hash('sha256', $html, false);
            }
            echo json_encode([
                'renders' => $count,
                'output_sha256' => $hash,
                'render_latencies_ns' => $latencies,
                'timer_overhead_ns' => $timerOverheadNs,
                'total_elapsed_ns' => $elapsed,
            ], JSON_THROW_ON_ERROR);
        }
    }
} finally {
    foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isFile() || $entry->isLink()) {
            unlink($entry->getPathname());
        }
    }
    rmdir($root);
}

if ($status !== 0) {
    exit($status);
}
