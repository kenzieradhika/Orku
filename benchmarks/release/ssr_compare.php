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
$renders = filter_var(
    option('--renders', '1'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 1000]]
);
// --blade-mode: 'file' (equivalent to Orku file-based ViewRenderer) or
//               'string' (low-level compileString+eval microbenchmark, labeled separately).
$bladeMode = option('--blade-mode', 'file');
if (
    !in_array($framework, ['orku', 'laravel'], true)
    || !in_array($mode, ['cases', 'render'], true)
    || $renders === false
    || !in_array($bladeMode, ['file', 'string'], true)
) {
    fwrite(STDERR, "usage: ssr_compare.php --framework orku|laravel --mode cases|render --renders 1..1000"
        ." [--dependency-root path] [--blade-mode file|string]\n");
    exit(2);
}

$root = option('--dependency-root', '');
$cache = null;
$bladeSrcDir = null;
$compiledDir = null;
$temp = null;
$compiledTemplates = [];

if ($framework === 'laravel') {
    $autoload = $root.'/vendor/autoload.php';
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
    if (!is_file($autoload)) {
        fwrite(STDERR, "Laravel 12.71.0 dependency root incomplete; composer.lock and vendor required\n");
        exit(3);
    }
    require $autoload;

    if ($bladeMode === 'file') {
        // Equivalent mode: Blade templates are written as .blade.php source files, compiled to
        // .php files on disk via BladeCompiler::compile(), then included. Same abstraction level
        // as Orku's ViewRenderer (resolve path → include compiled file).
        $bladeSrcDir = sys_get_temp_dir().'/orku-blade-src-'.bin2hex(random_bytes(8));
        $compiledDir = sys_get_temp_dir().'/orku-blade-compiled-'.bin2hex(random_bytes(8));
        mkdir($bladeSrcDir, 0700);
        mkdir($compiledDir, 0700);

        $bladeFs = new Illuminate\Filesystem\Filesystem();
        $compiler = new Illuminate\View\Compilers\BladeCompiler($bladeFs, $compiledDir);

        // Write Blade source templates to disk, compile each, cache compiled paths.
        $compiledPaths = [];
        $writeAndCompile = static function (string $key, string $bladeSource) use (
            $bladeSrcDir,
            $compiledDir,
            $compiler,
            &$compiledPaths
        ): void {
            if (isset($compiledPaths[$key])) {
                return;
            }
            $srcPath = $bladeSrcDir.'/'.$key.'.blade.php';
            file_put_contents($srcPath, $bladeSource);
            $compiler->compile($srcPath);
            $compiledPath = $compiler->getCompiledPath($srcPath);
            $compiledPaths[$key] = $compiledPath;
        };

        $render = static function (string $key, string $bladeSource, array $data = []) use (
            $writeAndCompile,
            &$compiledPaths
        ): string {
            $writeAndCompile($key, $bladeSource);
            extract($data, EXTR_SKIP);
            ob_start();
            try {
                include $compiledPaths[$key];
                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        $cacheState = 'blade_file_compiled_per_process';
    } else {
        // String mode (labeled separately): compileString+eval. This is a low-level microbenchmark
        // of Blade compilation without file I/O, not equivalent to Orku's ViewRenderer.
        $cache = sys_get_temp_dir().'/orku-blade-cache-'.bin2hex(random_bytes(8));
        mkdir($cache, 0700);
        $blade = new Illuminate\View\Compilers\BladeCompiler(
            new Illuminate\Filesystem\Filesystem(),
            $cache
        );
        $render = static function (string $key, string $template, array $data = []) use (
            $blade,
            &$compiledTemplates
        ): string {
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
        $cacheState = 'blade_string_compiled_per_process';
    }
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
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $template);
    }
    $views = new ViewRenderer([$temp]);
    $render = static function (string $name, string $_source = '', array $data = [], array $slots = []) use ($views): string {
        return $views->render($name, $data, $slots);
    };
    $cacheState = 'ViewRenderer_path_cache_per_process';
    $version = PHP_VERSION;
}

// Blade source templates (Blade syntax, used for Laravel modes).
// Keys shared with Orku template names for the parity check.
$bladeSources = [
    'static' => '<p>Release validation</p>',
    'dynamic' => '<p>{{ $value }}</p>',
    'utf8' => '<p>{{ $value }}</p>',
    'null' => '<p>{{ $value }}</p>',
    'empty' => '<p>{{ $value }}</p>',
    'escaped' => '<p>{{ $value }}</p>',
    'layout' => '<main><header>{!! $header !!}</header>{!! $content !!}</main>',
    'page' => '<article>{{ $title }}{!! $partial !!}</article>',
    'partial' => '<b>{{ $label }}</b>',
    'collection_small' => '<ul><?php foreach ($items as $item): ?><li>{{ $item }}</li><?php endforeach ?></ul>',
    'collection_large' => '<ul><?php foreach ($items as $item): ?><li>{{ $item }}</li><?php endforeach ?></ul>',
    'conditional' => '@if($show)<strong>{{ $value }}</strong>@endif',
    'conditional_hidden' => '@if($show)<strong>{{ $value }}</strong>@endif',
];

$large = array_map(static fn (int $i): string => 'row-'.$i, range(1, 500));

$caseData = [
    'static' => [[], []],
    'dynamic' => [['value' => 'Hello Orku'], []],
    'utf8' => [['value' => 'café 東京'], []],
    'null' => [['value' => null], []],
    'empty' => [['value' => ''], []],
    'escaped' => [['value' => '<script>alert(1)</script>'], []],
    'layout_partial' => [['title' => 'News', 'label' => 'new', 'header' => '<h1>Latest</h1>'], []],
    'collection_small' => [['items' => ['one', 'two', 'three']], []],
    'collection_large' => [['items' => $large], []],
    'conditional' => [['show' => true, 'value' => 'visible'], []],
    'conditional_hidden' => [['show' => false, 'value' => 'hidden'], []],
];

$cases = [];
if ($framework === 'orku') {
    foreach (array_keys($caseData) as $name) {
        [$data, $slots] = $caseData[$name];
        if ($name === 'layout_partial') {
            $page = $views->render('page', ['title' => 'News']);
            $cases[$name] = $views->render('layout', [], [
                'header' => Orku\Web\TrustedHtml::fromTrustedString('<h1>Latest</h1>'),
                'content' => Orku\Web\TrustedHtml::fromTrustedString($page),
            ]);
        } else {
            $orkuTemplate = match ($name) {
                'static' => 'static',
                'dynamic', 'utf8', 'null', 'empty', 'escaped' => 'value',
                'collection_small' => 'small',
                'collection_large' => 'large',
                'conditional', 'conditional_hidden' => 'conditional',
            };
            $cases[$name] = $render($orkuTemplate, '', $data, $slots);
        }
    }
} else {
    foreach ($bladeSources as $name => $src) {
        if (in_array($name, ['layout', 'page', 'partial'], true)) {
            continue;
        }
        [$data] = $caseData[$name];
        if ($name === 'layout_partial') {
            $partial = $render('partial', $bladeSources['partial'], ['label' => 'new']);
            $page = $render('page', $bladeSources['page'], ['title' => 'News', 'partial' => $partial]);
            $cases[$name] = $render('layout', $bladeSources['layout'], [
                'header' => '<h1>Latest</h1>', 'content' => $page,
            ]);
        } else {
            $cases[$name] = $render($name, $src, $data);
        }
    }
    $partial = $render('partial', $bladeSources['partial'], ['label' => 'new']);
    $page = $render('page', $bladeSources['page'], ['title' => 'News', 'partial' => $partial]);
    $cases['layout_partial'] = $render('layout', $bladeSources['layout'], [
        'header' => '<h1>Latest</h1>', 'content' => $page,
    ]);
}

try {
    if ($mode === 'cases') {
        echo json_encode(
            ['framework' => $framework, 'blade_mode' => $framework === 'laravel' ? $bladeMode : 'n/a',
                'version' => $framework === 'laravel' ? $version : 'Orku', 'outputs' => $cases],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
    } else {
        // Warm-render benchmark: single-template sample re-using the same resolution path.
        $sampleSrc = '<p>{{ $value }}</p>';
        $sampleData = ['value' => 'benchmark'];
        if ($framework === 'orku') {
            $sampleRender = static function () use ($render): string {
                return $render('value', '', ['value' => 'benchmark']);
            };
        } elseif ($bladeMode === 'file') {
            // Pre-compile the sample template once (counted outside measured loop).
            $render('__sample', $sampleSrc, $sampleData);
            $sampleRender = static function () use ($render, $sampleSrc, $sampleData): string {
                return $render('__sample', $sampleSrc, $sampleData);
            };
        } else {
            $sampleRender = static function () use ($render, $sampleSrc, $sampleData): string {
                return $render('__sample', $sampleSrc, $sampleData);
            };
        }

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
        echo json_encode(
            ['framework' => $framework, 'blade_mode' => $framework === 'laravel' ? $bladeMode : 'n/a',
                'renders' => $renders,
                'output_sha256' => hash('sha256', $html), 'render_latencies_ns' => $latencies,
                'total_elapsed_ns' => $elapsed, 'timer_overhead_ns' => $timerOverhead,
                'cold_first_render_ns' => $coldFirstRender,
                'warmup_renders' => 100,
                'cache_state' => $cacheState,
                'cpu_user_us' => ($cpu['ru_utime.tv_sec'] - $cpuStart['ru_utime.tv_sec']) * 1000000 + $cpu['ru_utime.tv_usec'] - $cpuStart['ru_utime.tv_usec'],
                'cpu_system_us' => ($cpu['ru_stime.tv_sec'] - $cpuStart['ru_stime.tv_sec']) * 1000000 + $cpu['ru_stime.tv_usec'] - $cpuStart['ru_stime.tv_usec'],
                'memory_bytes' => 'UNMEASURED'],
            JSON_THROW_ON_ERROR
        );
    }
} finally {
    if ($framework === 'orku' && is_string($temp) && is_dir($temp)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($temp);
    } elseif ($framework === 'laravel') {
        if (is_string($compiledDir) && is_dir($compiledDir)) {
            foreach (glob($compiledDir.'/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($compiledDir);
        }
        if (is_string($bladeSrcDir) && is_dir($bladeSrcDir)) {
            foreach (glob($bladeSrcDir.'/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($bladeSrcDir);
        }
        if (is_string($cache) && is_dir($cache)) {
            foreach (glob($cache.'/*') ?: [] as $compiled) {
                unlink($compiled);
            }
            rmdir($cache);
        }
    }
}
