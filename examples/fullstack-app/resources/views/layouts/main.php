<?php declare(strict_types=1); /** @var Orku\Web\ViewContext $view */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="A working PHP-native rendering and secure forms demonstration for Orku Framework.">
    <title><?= $view->escape($view->get('title')) ?> | Orku Web Demo</title>
    <link rel="stylesheet" href="<?= $view->url($view->string('css')) ?>">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<?= $view->component('navbar', ['prefix' => $view->get('prefix')]) ?>
<main id="main" tabindex="-1" class="container">
    <?= $view->raw($view->slot('content')) ?>
</main>
<?= $view->component('footer') ?>
<?= $view->raw($view->slot('footer')) ?>
</body>
</html>
