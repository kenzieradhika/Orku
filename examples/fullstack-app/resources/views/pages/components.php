<?php declare(strict_types=1);
use Orku\Web\TrustedHtml; /** @var Orku\Web\ViewContext $view */

?>
<section class="intro compact"><h1>Components,<br>without a compiler.</h1><p class="lead">Explicit props in. Server-rendered HTML out. These are the same pieces used across this demo.</p></section>
<section class="specimen"><h2>Link button</h2><div><?= $view->component('button', ['href' => $view->string('prefix').'/contact', 'label' => 'Open feedback form']) ?><p>A real link with a validated destination. Works without JavaScript.</p></div></section>
<section class="specimen"><h2>Card + named slot</h2><div><?= $view->component('card', ['title' => 'A small rendering boundary'], ['content' => TrustedHtml::fromTrustedString('<p>This paragraph is application-owned HTML passed through the content slot.</p>')]) ?></div></section>
<section class="specimen"><h2>Alert</h2><div><?= $view->component('alert', ['title' => 'Demonstration only', 'message' => 'Feedback is validated, not stored or sent.']) ?></div></section>
<section class="specimen"><h2>Input + field errors</h2><div><p>The feedback page demonstrates required fields, email validation, preserved values and field-level error messages.</p><a class="text-link" href="<?= $view->url($view->string('prefix').'/contact') ?>">Use the working form</a></div></section>
<section class="specimen"><h2>Local image</h2><figure><img src="<?= $view->url($view->string('image')) ?>" width="480" height="180" alt="Placeholder image showing where an application-owned static asset appears"><figcaption>Placeholder asset, served from the public assets directory. No external image service.</figcaption></figure></section>
