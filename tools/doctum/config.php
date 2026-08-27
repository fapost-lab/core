<?php

declare(strict_types=1);

use Doctum\Doctum;
use Symfony\Component\Finder\Finder;

$paths = array_filter([
    __DIR__ . '/../../app/Domains',
    __DIR__ . '/../../packages/fapost-foundation/src',
    __DIR__ . '/../../packages/fapost-support/src',
], static fn (string $path): bool => is_dir($path));

$iterator = Finder::create()
    ->files()
    ->name('*.php')
    ->exclude([
        'database',
        'resources',
        'tests',
    ])
    ->in($paths);

return new Doctum($iterator, [
    'title'                => 'FAPost Core API',
    'build_dir'            => __DIR__ . '/../../public/core',
    'cache_dir'            => __DIR__ . '/cache',
    'default_opened_level' => 2,
]);
