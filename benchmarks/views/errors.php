<?php declare(strict_types=1); /** @var Orku\Web\ViewContext $view */ ?>
<h1>Check your input</h1><p><?= $view->escape(json_encode($view->get('errors'), JSON_THROW_ON_ERROR)) ?></p>
