<?php

use T3\Size\Controller\StorageStatisticsController;

return [
    'size_storage_statistics' => [
        'parent' => 'system',
        'access' => 'user',
        'path' => '/module/system/size/storage-statistics',
        'packageName' => 't3/size',
        'iconIdentifier' => 'actions-download',
        'labels' => 'LLL:EXT:size/Resources/Private/Language/locallang_mod.xlf',
        'appearance' => [
            'renderInModuleMenu' => false,
        ],
        'routes' => [
            '_default' => [
                'target' => StorageStatisticsController::class . '::overviewAction',
            ],
        ],
    ],
];
