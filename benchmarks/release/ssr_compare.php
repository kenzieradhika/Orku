<?php

declare(strict_types=1);

foreach (['WebException.php', 'TrustedHtml.php', 'ViewContext.php', 'ViewRenderer.php'] as $file) {
    require dirname(__DIR__, 2).'/src/Web/'.$file;
}

use Orku\Web\ViewRenderer;

function option(string $name, string $default): string
{
    global $argv;
    $index = array_search($name, $argv, true);
    return $index === false ? $default : (string) ($argv[$index + 1] ?? '');
}

$framework = option('--framework', '');
$mode = option('--mode', '');
$renders = filter_var(option('--renders', '1'), FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 1000]]);
if (!in_array($framework, ['orku', 'laravel'], true) || !in_array($mode, ['cases', 'render'], true) || $renders === false) {
    fwrite(STDERR, "usage: ssr_compare.php --framework orku|laravel --mode cases|render --renders 1..1000 [--dependency-root path]\n");
    exit(2);
}

$root = option('--dependency-root', '');
$cache = null;
$temp = null;
$compiledTemplates = [];
if ($framework === 'laravel') {
    $autoload = $root.'/vendor/autoload.php';
    $package = $root.'/vendor/laravel/framework/src/Illuminate/Foundation/Application.php';
    $lock = $root.'/composer.lock';
    if (!is_file($lock)) {
        fwrite(STDERR, "Laravel 12.71.0 dependency root incomplete; composer.lock and vendor required\n");
        exit(3);
    }
    $lockData = json_decode((string) file_get_contents($lock), true, flags: JSON_THROW_ON_ERROR);
    $version = null;
    foreach ($lockData['packages'] ?? [] as $dependency) {
        if (($dependency['name'] ?? '') === 'laravel/framework') {
            $version = ltrim((string) ($dependency['version'] ?? ''), 'v');
        }
    }
    if ($version !== '12.71.0') {
        fwrite(STDERR, "Laravel version must be 12.71.0; got ".($version ?? 'missing')."\n");
        exit(3);
    }
    if (!is_file($autoload) || !is_file($package)) {
        fwrite(STDERR, "Laravel 12.71.0 dependency root incomplete; composer.lock and vendor required\n");
        exit(3);
    }
    require $autoload;
    $cache = sys_get_temp_dir().'/orku-blade-cache-'.bin2hex(random_bytes(8));
    mkdir($cache, 0700);
    $blade = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem(), $cache);
    $compiledTemplates = [];
    $render = static function (string $template, array $data = []) use ($blade, &$compiledTemplates): string {
        $key = hash('sha256', $template);
        $compiled = $compiledTemplates[$key] ??= $blade->compileString($template);
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            eval('?>'.$compiled);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    };
} else {
    $temp = sys_get_temp_dir().'/orku-ssr-compare-'.bin2hex(random_bytes(8));
    if (!mkdir($temp, 0700)) {
        throw new RuntimeException('Could not create fixture directory.');
    }
    mkdir($temp.'/parts');
    $templates = [
        'static' => '<p>Release validation</p>',
        'value' => '<p><?= $view->escape($view->get("value")) ?></p>',
        'layout' => '<main><header><?= $view->raw($view->slot("header")) ?></header><?= $view->raw($view->slot("content")) ?></main>',
        'page' => '<article><?= $view->escape($view->get("title")) ?><?= $view->partial("parts.badge", ["label" => "new"]) ?></article>',
        'parts.badge' => '<b><?= $view->escape($view->get("label")) ?></b>',
        'small' => '<ul><?php foreach ($view->get("items") as $item): ?><li><?= $view->escape($item) ?></li><?php endforeach ?></ul>',
        'large' => '<ul><?php foreach ($view->get("items") as $item): ?><li><?= $view->escape($item) ?></li><?php endforeach ?></ul>',
        'conditional' => '<?php if ($view->get("show")): ?><strong><?= $view->escape($view->get("value")) ?></strong><?php endif ?>',
    ];
    foreach ($templates as $name => $template) {
        $path = $temp.'/'.str_replace('.', '/', $name).'.php';
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, $template);
    }
    $views = new ViewRenderer([$temp]);
    $render = static fn (string $template, array $data = [], array $slots = []): string => $views->render($template, $data, $slots);
    $version = PHP_VERSION;
}

$large = array_map(static fn (int $i): string => 'row-'.$i, range(1, 500));
$cases = [
    'static' => $render('static'),
    'dynamic' => $render('value', ['value' => 'Hello Orku']),
    'utf8' => $render('value', ['value' => 'café 東京']),
    'null' => $render('value', ['value' => null]),
    'empty' => $render('value', ['value' => '']),
    'escaped' => $render('value', ['value' => '<script>alert(1)</script>']),
    'layout_partial' => $framework === 'orku'
        ? $views->layout('layout', 'page', ['title' => 'News'], ['header' => Orku\Web\TrustedHtml::fromTrustedString('<h1>Latest</h1>')])
        : $render('layout', ['title' => 'News'], ['header' => '<h1>Latest</h1>', 'content' => $render('page', ['title' => 'News'])]),
    'collection_small' => $render('small', ['items' => ['one', 'two', 'three']]),
    'collection_large' => $render('large', ['items' => $large]),
    'conditional' => $render('conditional', ['show' => true, 'value' => 'visible']),
    'conditional_hidden' => $render('conditional', ['show' => false, 'value' => 'hidden']),
];
if ($framework === 'laravel') {
    $bladeCases = [];
    $bladeTemplates = [
        'static' => '<p>Release validation</p>',
        'dynamic' => '<p>{{ $value }}</p>', 'utf8' => '<p>{{ $value }}</p>',
        'null' => '<p>{{ $value }}</p>', 'empty' => '<p>{{ $value }}</p>',
        'escaped' => '<p>{{ $value }}</p>',
        'layout_partial' => '<main><header>{!! $header !!}</header><article>{{ $title }}<b>{{ $label }}</b></article></main>',
        'collection_small' => '<ul><?php foreach ($items as $item): ?><li>{{ $item }}</li><?php endforeach ?></ul>',
        'collection_large' => '<ul><?php foreach ($items as $item): ?><li>{{ $item }}</li><?php endforeach ?></ul>',
        'conditional' => '@if($show)<strong>{{ $value }}</strong>@endif',
        'conditional_hidden' => '@if($show)<strong>{{ $value }}</strong>@endif',
    ];
    $data = [
        'dynamic' => ['value' => 'Hello Orku'], 'utf8' => ['value' => 'café 東京'],
        'null' => ['value' => null], 'empty' => ['value' => ''],
        'escaped' => ['value' => '<script>alert(1)</script>'],
        'layout_partial' => ['header' => '<h1>Latest</h1>', 'title' => 'News', 'label' => 'new'],
        'collection_small' => ['items' => ['one', 'two', 'three']],
        'collection_large' => ['items' => $large],
        'conditional' => ['show' => true, 'value' => 'visible'],
        'conditional_hidden' => ['show' => false, 'value' => 'hidden'],
    ];
    foreach ($bladeTemplates as $name => $template) {
        $bladeCases[$name] = $render($template, $data[$name] ?? []);
    }
    $cases = $bladeCases;
}

try {
    if ($mode === 'cases') {
        echo json_encode(['framework' => $framework, 'version' => $framework === 'laravel' ? $version : 'Orku', 'outputs' => $cases], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } else {
        if ($framework === 'laravel') {
            $compiledTemplates = [];
            $compiledTemplates = [];
            $render = static function (string $template, array $data = []) use ($blade, &$compiledTemplates): string {
                $key = hash('sha256', $template);
                $compiled = $compiledTemplates[$key] ??= $blade->compileString($template);
                extract($data, EXTR_SKIP);
                ob_start();
                try {
                    eval('?>'.$compiled);
                    return (string) ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            };
        }
        $sampleRender = static function () use ($framework, $render): string {
            return $framework === 'orku' ? $render('value', ['value' => 'benchmark']) : $render('<p>{{ $value }}</p>', ['value' => 'benchmark']);
        };
        $coldStarted = hrtime(true);
        $coldHtml = $sampleRender();
        $coldFirstRender = hrtime(true) - $coldStarted;
        for ($i = 0; $i < 100; ++$i) {
            $sampleRender();
        }
        $timerStart = hrtime(true);
        for ($i = 0; $i < 1000; ++$i) {
            $tick = hrtime(true);
            hrtime(true) - $tick;
        }
        $timerOverhead = (hrtime(true) - $timerStart) / 1000;
        $cpuStart = getrusage();
        $latencies = [];
        $started = hrtime(true);
        for ($i = 0; $i < $renders; ++$i) {
            $tick = hrtime(true);
            $html = $sampleRender();
            $latencies[] = max(0, hrtime(true) - $tick - $timerOverhead);
        }
        $elapsed = hrtime(true) - $started;
        $cpu = getrusage();
        echo json_encode(['framework' => $framework, 'renders' => $renders,
            'output_sha256' => hash('sha256', $html), 'render_latencies_ns' => $latencies,
            'total_elapsed_ns' => $elapsed, 'timer_overhead_ns' => $timerOverhead,
            'cold_first_render_ns' => $coldFirstRender,
            'warmup_renders' => 100,
            'cache_state' => $framework === 'laravel' ? 'compiled_blade_cache_per_process' : 'ViewRenderer_path_cache_per_process',
            'cpu_user_us' => ($cpu['ru_utime.tv_sec'] - $cpuStart['ru_utime.tv_sec']) * 1000000 + $cpu['ru_utime.tv_usec'] - $cpuStart['ru_utime.tv_usec'],
            'cpu_system_us' => ($cpu['ru_stime.tv_sec'] - $cpuStart['ru_stime.tv_sec']) * 1000000 + $cpu['ru_stime.tv_usec'] - $cpuStart['ru_stime.tv_usec'],
            'memory_bytes' => 'UNMEASURED'], JSON_THROW_ON_ERROR);
    }
} finally {
    if ($framework === 'orku') {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($temp);
    } elseif (is_string($cache) && is_dir($cache)) {
        foreach (glob($cache.'/*') ?: [] as $compiled) unlink($compiled);
        rmdir($cache);
    }
}
