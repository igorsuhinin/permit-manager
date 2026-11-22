<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@PER-CS3x0' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'php_unit_strict' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self', 'target' => 'newest'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'blank_line_between_import_groups' => false,
        'single_line_throw' => false,
    ])
    ->setFinder(PhpCsFixer\Finder::create()
        ->in([
            __DIR__ . '/migrations', __DIR__ . '/bin', __DIR__ . '/src',
            __DIR__ . '/translations', __DIR__ . '/config', __DIR__ . '/bootstrap', __DIR__ . '/tests',
        ])
        ->append([__DIR__ . '/public/index.php', __DIR__ . '/rector.php', __FILE__]));
