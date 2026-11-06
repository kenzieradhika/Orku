<?php declare(strict_types=1); /** @var Orku\Web\ViewContext $view */ ?>
<section class="intro compact"><p>HTTP <?= $view->escape($view->get('status')) ?></p><h1><?= $view->escape($view->get('title')) ?></h1><p class="lead">No page content was returned. Check the address or return home.</p><?= $view->component('button', ['href' => $view->string('prefix').'/', 'label' => 'Return home']) ?></section>
<?php if ($view->get('detail') !== ''): ?><section><h2>Development diagnostics</h2><pre><?= $view->escape($view->get('detail')) ?></pre></section><?php endif; ?>
