<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Size',
    'description' => 'Displays TYPO3 CMS storage usage information in the backend.',
    'version' => '0.1.0-dev',
    'category' => 'be',
    'state' => 'stable',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
        ],
    ],
    'autoload' => [
        'psr-4' => ['T3\\Size\\' => 'Classes']
    ],
];
