<?php

declare(strict_types=1);

use Orku\Core\Kernel;
use Orku\Runtime\SapiRuntime;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$router = require dirname(__DIR__).'/app.php';
if (!$router instanceof Orku\Routing\Router) {
    throw new RuntimeException('Application must return Router.');
}
SapiRuntime::run(new Kernel($router));
