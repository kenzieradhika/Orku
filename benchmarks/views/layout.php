<?php declare(strict_types=1); /** @var Orku\Web\ViewContext $view */ ?>
<!doctype html><html lang="en"><head><title><?= $view->escape($view->get('title')) ?></title></head><body><?= $view->raw($view->slot('content')) ?></body></html>
