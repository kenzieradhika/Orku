<?php declare(strict_types=1); /** @var Orku\Web\ViewContext $view */ ?>
<div class="notice" role="status"><strong><?= $view->escape($view->get('title')) ?></strong><p><?= $view->escape($view->get('message')) ?></p></div>
