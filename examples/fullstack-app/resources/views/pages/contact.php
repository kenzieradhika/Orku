<?php declare(strict_types=1);
/** @var Orku\Web\ViewContext $view */ $errors = $view->get('errors');
$fields = $view->get('fields');
if (!is_array($errors) || !is_array($fields)) {
    throw new Orku\Web\WebException('Expected form data.');
} ?>
<section class="intro compact"><h1>A form that stays<br>on the server.</h1><p class="lead">Try the validation flow. This demonstration checks your feedback but does not store or send it. Do not enter sensitive information.</p></section>
<div class="form-layout"><form action="<?= $view->url($view->string('prefix').'/contact') ?>" method="post" novalidate>
    <input type="hidden" name="_csrf" value="<?= $view->escape($view->get('token')) ?>">
    <?php if ($errors !== []): ?><div class="error-summary" role="alert"><h2>Check these fields</h2><p>Correct the marked fields below, then submit again.</p></div><?php endif; ?>
    <?php foreach (['name' => 'Name', 'email' => 'Email address', 'message' => 'Feedback'] as $name => $label): ?>
        <?= $view->component('field', ['name' => $name, 'label' => $label, 'value' => $fields[$name] ?? '', 'errors' => $errors[$name] ?? []]) ?>
    <?php endforeach; ?>
    <button class="button" type="submit">Validate demo feedback</button>
</form><aside><h2>What happens here?</h2><p>POST submits a bounded URL-encoded body. The server verifies your CSRF token before validating fields.</p><p>Invalid fields return HTTP 422. A valid submission redirects with HTTP 303. No message is delivered.</p><p>Name: 2–80 characters.<br>Feedback: 10–2,000 characters.<br>Email: valid syntax required.</p></aside></div>
