<?php

declare(strict_types=1);

use CodeIgniter\CodingStandard\CodeIgniter4;
use Nexus\CsConfig\Factory;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->files()
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return Factory::create(new CodeIgniter4(), [], [
    'finder'     => $finder,
    'cacheFile'  => 'build/.php-cs-fixer.cache',
    'usingCache' => true,
])->forProjects();
