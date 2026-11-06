<?php declare(strict_types=1); /** @var Orku\Web\ViewContext $view */ ?>
<article class="card"><h3><?= $view->escape($view->get('title')) ?></h3><?= $view->raw($view->slot('content')) ?></article>
