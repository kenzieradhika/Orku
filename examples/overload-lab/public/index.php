<?php

declare(strict_types=1);

use Orku\Core\Kernel;
use Orku\Routing\Router;
use Orku\Runtime\SapiRuntime;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$router = require dirname(__DIR__).'/app.php';
if (!$router instanceof Router) {
    throw new RuntimeException('Overload lab app fixture must return a Router.');
}
SapiRuntime::run(new Kernel($router));
