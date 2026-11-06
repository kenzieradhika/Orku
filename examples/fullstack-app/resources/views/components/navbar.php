<?php declare(strict_types=1);
/** @var Orku\Web\ViewContext $view */ $base = $view->string('prefix'); ?>
<header class="site-header container">
    <a class="wordmark" href="<?= $view->url($base.'/') ?>">Orku<span>Web Demo</span></a>
    <nav aria-label="Main navigation">
        <a href="<?= $view->url($base.'/') ?>">Home</a>
        <a href="<?= $view->url($base.'/about') ?>">About</a>
        <a href="<?= $view->url($base.'/components') ?>">Components</a>
        <a href="<?= $view->url($base.'/contact') ?>">Feedback</a>
    </nav>
</header>
