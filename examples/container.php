<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$container = new Orku\Container\Container();
$container->bind('user', fn () => (object) ['name' => 'Ada'], 'request');
$scope = $container->scope();
try {
    $user = $scope->get('user');
    echo json_encode($user, JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $scope->close();
}
