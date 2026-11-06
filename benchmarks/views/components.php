<?php declare(strict_types=1);
/** @var Orku\Web\ViewContext $view */ $items = $view->get('items');
if (!is_array($items)) {
    throw new Orku\Web\WebException('Expected items.');
} ?>
<?php foreach ($items as $item): ?><?= $view->component('item', ['label' => $item]) ?><?php endforeach; ?>
