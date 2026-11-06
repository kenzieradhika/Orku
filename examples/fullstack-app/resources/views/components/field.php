<?php declare(strict_types=1);
/** @var Orku\Web\ViewContext $view */ $name = $view->string('name');
$errors = $view->get('errors');
if (!is_array($errors)) {
    throw new Orku\Web\WebException('Expected field errors.');
} ?>
<div class="field">
    <label for="<?= $view->escape($name) ?>"><?= $view->escape($view->get('label')) ?> <span>(required)</span></label>
    <?php if ($name === 'message'): ?>
        <textarea id="<?= $view->escape($name) ?>" name="<?= $view->escape($name) ?>" rows="6" maxlength="2000" required aria-invalid="<?= $errors === [] ? 'false' : 'true' ?>" aria-describedby="<?= $view->escape($name) ?>-error"><?= $view->escape($view->get('value')) ?></textarea>
    <?php else: ?>
        <input id="<?= $view->escape($name) ?>" name="<?= $view->escape($name) ?>" type="<?= $name === 'email' ? 'email' : 'text' ?>" autocomplete="<?= $view->escape($name) ?>" maxlength="<?= $name === 'email' ? '254' : '80' ?>" required value="<?= $view->escape($view->get('value')) ?>" aria-invalid="<?= $errors === [] ? 'false' : 'true' ?>" aria-describedby="<?= $view->escape($name) ?>-error">
    <?php endif; ?>
    <div id="<?= $view->escape($name) ?>-error" class="field-error"><?php foreach ($errors as $error): ?><p><?= $view->escape($error) ?></p><?php endforeach; ?></div>
</div>
