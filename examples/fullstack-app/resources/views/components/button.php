<?php declare(strict_types=1); /** @var Orku\Web\ViewContext $view */ ?>
<a class="button" href="<?= $view->url($view->string('href')) ?>"><?= $view->escape($view->get('label')) ?></a>
