<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules(['@PSR12' => true, 'declare_strict_types' => true])
    ->setFinder(PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/examples', __DIR__.'/benchmarks'])
        ->exclude([
            'benchmarks/crud-api/laravel/vendor',
            'benchmarks/crud-api/laravel/bootstrap/cache',
            'benchmarks/crud-api/laravel/storage',
        ])
        ->append([__DIR__.'/bin/orku']));
