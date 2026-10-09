<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude('var')
;

$config = new PhpCsFixer\Config();
$config
    ->setRules([
        '@Symfony' => true,
        'array_syntax' => ['syntax' => 'short'],
        'yoda_style' => ['equal' => false, 'identical' => false],
        'no_null_property_initialization' => true,
    ])
    ->setFinder($finder)
;

return $config;
