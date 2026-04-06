<?php

declare(strict_types=1);

use Doctum\Doctum;
use Symfony\Component\Finder\Finder;

$iterator = Finder::create()
    ->files()
    ->name('*.php')
    ->exclude('Console')
    ->in(__DIR__ . '/../../app');

return new Doctum($iterator, [
    'title'                => 'Console API',
    'build_dir'            => __DIR__ . '/../../public/core',
    'cache_dir'            => __DIR__ . '/cache',
    'default_opened_level' => 2,
]);
