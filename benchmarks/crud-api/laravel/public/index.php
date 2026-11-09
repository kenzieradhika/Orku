<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';
require dirname(__DIR__, 2).'/CrudStore.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $root.'/bootstrap/app.php';

$app->handleRequest(Illuminate\Http\Request::capture());
