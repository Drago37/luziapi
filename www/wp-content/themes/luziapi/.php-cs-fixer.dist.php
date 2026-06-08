<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/inc'])
    ->append([
        __DIR__ . '/functions.php',
        __DIR__ . '/front-page.php',
        __DIR__ . '/page.php',
        __DIR__ . '/index.php',
        __DIR__ . '/woocommerce.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'               => true,
        '@PHP82Migration'      => true,
        'declare_strict_types' => true,
        'ordered_imports'      => true,
        'no_unused_imports'    => true,
        'single_quote'         => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
