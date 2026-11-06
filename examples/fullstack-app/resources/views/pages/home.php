<?php declare(strict_types=1);
/** @var Orku\Web\ViewContext $view */ $base = $view->string('prefix'); ?>
<section class="intro">
    <h1>HTML first.<br>PHP throughout.</h1>
    <p class="lead">A page, a layout, a few reusable pieces. Orku Web brings server-rendered interfaces to Orku Core, without a frontend build step.</p>
    <?= $view->component('button', ['href' => $base.'/components', 'label' => 'See working components']) ?>
</section>
<section class="split" aria-labelledby="render-heading">
    <div><h2 id="render-heading">One request.<br>Real HTML.</h2><p>This page comes from a trusted PHP template. Its layout owns the document, its components own the shared navigation, and the browser receives finished markup.</p><a class="text-link" href="<?= $view->url($base.'/about') ?>">Read the rendering contract</a></div>
    <div class="code-sample"><p>Page handler</p><pre><code>return Response::html(
    $views->render('pages.home', [
        'title' => 'Welcome to Orku',
    ])
);</code></pre></div>
</section>
<section class="capabilities"><h2>Less machinery. Clear boundaries.</h2><dl><div><dt>PHP-native views</dt><dd>Explicit escaping, layouts, partials and named slots. No template compiler.</dd></div><div><dt>Forms with server-side checks</dt><dd>Bounded parsing, field errors and session-backed CSRF. No database needed.</dd></div><div><dt>Core stays independent</dt><dd>JSON routes keep restrictive defaults. Sessions start only where requested.</dd></div></dl><a class="text-link" href="<?= $view->url($base.'/contact') ?>">Submit demo feedback</a></section>
