<?php

declare(strict_types=1);

use Orku\Core\Kernel;
use Orku\Runtime\SapiRuntime;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$router = require dirname(__DIR__).'/app.php';
SapiRuntime::run(new Kernel($router));
